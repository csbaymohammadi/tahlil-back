<?php

namespace App\Console\Commands;

use App\Models\BlogArticle;
use App\Support\PanelMutationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

class PublishDueBlogArticles extends Command
{
    protected $signature = 'blog:publish-due';

    protected $description = 'Publish due scheduled blog articles using durable database tasks';

    public function handle(): int
    {
        $this->reconcile();
        $tasks = DB::table('blog_schedule_tasks')->whereNull('processed_at')->where('due_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('available_at')->orWhere('available_at', '<=', now());
            })->orderBy('due_at')->orderBy('id')->limit(100)->pluck('id');
        $published = 0;
        foreach ($tasks as $taskId) {
            try {
                if ($this->publish((int) $taskId)) {
                    $published++;
                }
            } catch (Throwable $exception) {
                $attempts = (int) DB::table('blog_schedule_tasks')->where('id', $taskId)->value('attempts');
                DB::table('blog_schedule_tasks')->where('id', $taskId)->whereNull('processed_at')->update([
                    'attempts' => DB::raw('attempts + 1'),
                    'available_at' => now()->addSeconds(min(300, 5 * (2 ** min(6, $attempts)))),
                    'last_error' => $exception::class,
                    'updated_at' => now(),
                ]);
                report($exception);
            }
        }
        $this->info('Published '.$published.' scheduled article(s).');

        return self::SUCCESS;
    }

    private function reconcile(): void
    {
        $due = BlogArticle::query()->where('status', 'scheduled')->whereNotNull('scheduled_for')->where('scheduled_for', '<=', now())->limit(500)->get(['id', 'schedule_generation', 'scheduled_for']);
        foreach ($due as $article) {
            DB::table('blog_schedule_tasks')->insertOrIgnore([
                'article_id' => $article->id, 'generation' => $article->schedule_generation,
                'due_at' => $article->scheduled_for, 'attempts' => 0, 'available_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    private function publish(int $taskId): bool
    {
        return DB::transaction(function () use ($taskId): bool {
            PanelMutationLock::acquire();
            $task = DB::table('blog_schedule_tasks')->where('id', $taskId)->lockForUpdate()->first();
            if (! $task || $task->processed_at !== null) {
                return false;
            }
            $article = BlogArticle::withTrashed()->lockForUpdate()->find($task->article_id);
            if (! $article || $article->deleted_at !== null || $article->status !== 'scheduled' ||
                (int) $article->schedule_generation !== (int) $task->generation || ! $article->scheduled_for || $article->scheduled_for->isFuture()) {
                DB::table('blog_schedule_tasks')->where('id', $taskId)->update(['processed_at' => now(), 'updated_at' => now()]);

                return false;
            }
            $plannedAt = $article->scheduled_for->utc()->toISOString();
            $article->forceFill([
                'status' => 'published', 'scheduled_for' => null,
                'published_at' => $article->published_at ?? now(),
                'version' => $article->version + 1,
            ])->save();
            DB::table('blog_slug_ownership')->where('slug', $article->slug)->where('article_id', $article->id)->update(['was_public' => true]);
            DB::table('blog_schedule_tasks')->where('id', $taskId)->update(['processed_at' => now(), 'updated_at' => now(), 'last_error' => null]);
            DB::table('blog_audit_events')->insert([
                'actor_id' => null, 'target_type' => 'article', 'target_id' => $article->id,
                'event' => 'scheduled_publication', 'request_id' => 'system-blog-worker',
                'details' => json_encode(['planned_at' => $plannedAt, 'actual_at' => now()->utc()->toISOString(), 'author_id' => $article->author_id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
            ]);
            DB::table('blog_outbox')->insert([
                'article_id' => $article->id, 'event' => 'blog_article_published',
                'payload' => json_encode(['article_id' => $article->id, 'slug' => $article->slug, 'planned_at' => $plannedAt, 'actual_at' => now()->utc()->toISOString()], JSON_THROW_ON_ERROR),
                'attempts' => 0, 'available_at' => now(), 'created_at' => now(),
            ]);

            return true;
        });
    }
}
