<?php

namespace App\Actions\Demo;

use App\Actions\Blog\ManageCategories;
use App\Actions\Blog\SaveArticle;
use App\Actions\Bonuses\ManageBonusCategories;
use App\Actions\Bonuses\SaveBonus;
use App\Actions\Brokers\ManageBrokerCategories;
use App\Actions\Brokers\SaveBroker;
use App\Actions\Exchanges\ManageExchangeCategories;
use App\Actions\Exchanges\SaveExchange;
use App\Actions\GoldPlatforms\ManageGoldPlatformCategories;
use App\Actions\GoldPlatforms\SaveGoldPlatform;
use App\Actions\News\ManageNewsCategories;
use App\Actions\News\SaveNewsItem;
use App\Actions\PageSettings\SavePageSettings;
use App\Actions\PropFirms\ManagePropFirmCategories;
use App\Actions\PropFirms\SavePropFirm;
use App\Actions\StockBrokers\ManageStockBrokerageCategories;
use App\Actions\StockBrokers\SaveStockBrokerage;
use App\Auth\PanelSessions;
use App\Blog\BlogHtmlSanitizer;
use App\Models;
use App\Support\BrokerSpecifications;
use App\Support\CategoryAssignments;
use App\Support\ExchangeSpecifications;
use App\Support\GoldPlatformSpecifications;
use App\Support\PanelMutationLock;
use App\Support\PropFirmSpecifications;
use App\Support\PublicContentIndex;
use App\Support\StockBrokerSpecifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class SeedDemoContent
{
    public const IMAGE = '/images/demo-financial-content.png';

    /** @var array<string, array{model: class-string, save: class-string, manager: class-string, title: string, specs?: class-string}> */
    private const MODULES = [
        'articles' => ['model' => Models\BlogArticle::class, 'save' => SaveArticle::class, 'manager' => ManageCategories::class, 'title' => 'راهنمای نمونه مقایسه پلتفرم‌های مالی'],
        'news' => ['model' => Models\NewsItem::class, 'save' => SaveNewsItem::class, 'manager' => ManageNewsCategories::class, 'title' => 'خبر نمونه درباره شفافیت خدمات مالی'],
        'exchanges' => ['model' => Models\Exchange::class, 'save' => SaveExchange::class, 'manager' => ManageExchangeCategories::class, 'title' => 'صرافی نمونه تحلیل', 'specs' => ExchangeSpecifications::class],
        'brokers' => ['model' => Models\Broker::class, 'save' => SaveBroker::class, 'manager' => ManageBrokerCategories::class, 'title' => 'بروکر فارکس نمونه تحلیل', 'specs' => BrokerSpecifications::class],
        'stock-brokers' => ['model' => Models\StockBrokerage::class, 'save' => SaveStockBrokerage::class, 'manager' => ManageStockBrokerageCategories::class, 'title' => 'کارگزاری بورس نمونه تحلیل', 'specs' => StockBrokerSpecifications::class],
        'gold-platforms' => ['model' => Models\GoldPlatform::class, 'save' => SaveGoldPlatform::class, 'manager' => ManageGoldPlatformCategories::class, 'title' => 'پلتفرم طلای نمونه تحلیل', 'specs' => GoldPlatformSpecifications::class],
        'prop-firms' => ['model' => Models\PropFirm::class, 'save' => SavePropFirm::class, 'manager' => ManagePropFirmCategories::class, 'title' => 'پلتفرم پراپ نمونه تحلیل', 'specs' => PropFirmSpecifications::class],
        'bonuses' => ['model' => Models\Bonus::class, 'save' => SaveBonus::class, 'manager' => ManageBonusCategories::class, 'title' => 'بونوس نمونه تحلیل'],
    ];

    /** @var list<array{name: string, english: string, topic: string, detail: string, city: string}> */
    private const VARIANTS = [
        ['name' => 'آبان', 'english' => 'Aban', 'topic' => 'شفافیت کارمزد', 'detail' => 'هزینه ثبت سفارش، تبدیل دارایی و برداشت را جداگانه ثبت کنید تا هزینه نهایی خدمت پیش از تأیید قابل مقایسه باشد.', 'city' => 'تهران'],
        ['name' => 'سپهر', 'english' => 'Sepehr', 'topic' => 'امنیت حساب', 'detail' => 'فعال‌سازی ورود دومرحله‌ای، مشاهده نشست‌های فعال و دریافت اعلان ورود تازه، سه مسیر اصلی بررسی حفاظت از حساب هستند.', 'city' => 'شیراز'],
        ['name' => 'باران', 'english' => 'Baran', 'topic' => 'کیفیت پشتیبانی', 'detail' => 'برای یک درخواست آزمایشی، زمان اولین پاسخ و زمان حل مسئله را ثبت کنید و امکان پیگیری شماره تیکت را بسنجید.', 'city' => 'رشت'],
        ['name' => 'آرمان', 'english' => 'Arman', 'topic' => 'سرعت تسویه', 'detail' => 'زمان ثبت درخواست، تأیید داخلی و دریافت وجه را از هم تفکیک کنید؛ تعطیلات و سقف روزانه می‌توانند مدت تسویه را تغییر دهند.', 'city' => 'اصفهان'],
        ['name' => 'ترنج', 'english' => 'Toranj', 'topic' => 'تجربه کاربری موبایل', 'detail' => 'خوانایی جدول‌ها، دسترسی به دکمه اصلی و تکمیل فرم با صفحه‌کلید موبایل را در نمایشگر کوچک بررسی کنید.', 'city' => 'تبریز'],
        ['name' => 'آتیه', 'english' => 'Atieh', 'topic' => 'احراز هویت', 'detail' => 'نوع مدارک، مدت بررسی درخواست و روش اصلاح اطلاعات ناقص باید پیش از شروع ثبت‌نام برای کاربر روشن باشد.', 'city' => 'یزد'],
        ['name' => 'پارس', 'english' => 'Pars', 'topic' => 'گزارش‌گیری مالی', 'detail' => 'تاریخچه عملیات باید قابلیت جست‌وجو بر اساس تاریخ و وضعیت داشته باشد و خروجی آن با مانده حساب قابل تطبیق باشد.', 'city' => 'مشهد'],
        ['name' => 'روشنا', 'english' => 'Roshna', 'topic' => 'محدودیت برداشت', 'detail' => 'حداقل مبلغ، سقف هر درخواست و محدودیت دفعات برداشت را کنار روش انتقال ثبت کنید تا شرایط نقدشوندگی قابل ارزیابی باشد.', 'city' => 'کرمان'],
        ['name' => 'کارون', 'english' => 'Karun', 'topic' => 'شرایط قرارداد', 'detail' => 'مقررات لغو خدمت، تغییر تعرفه و روش رسیدگی به اختلاف باید متن روشن و نسخه قابل مراجعه داشته باشند.', 'city' => 'اهواز'],
        ['name' => 'نارون', 'english' => 'Narvan', 'topic' => 'آزمایش خدمات پیش از شروع', 'detail' => 'در محیط آزمایشی، مسیر ایجاد سفارش، اصلاح اشتباه و دریافت گزارش را بررسی کنید؛ نتیجه آزمایش را با شرایط حساب واقعی یکسان فرض نکنید.', 'city' => 'کرمانشاه'],
    ];

    public function __construct(private PanelSessions $sessions, private SavePageSettings $settings, private PublicContentIndex $index) {}

    /** @return list<array{module: string, id: int, slug: string, result: string}> */
    public function handle(Models\User $actor): array
    {
        if (! $actor->is_active || $actor->trashed() || trim($actor->username ?? '') === '') {
            throw new RuntimeException('Choose an existing active panel account as the demo author.');
        }
        if (! is_file(public_path(ltrim(self::IMAGE, '/')))) {
            throw new RuntimeException('The bundled demo image is missing. Deploy the complete commit first.');
        }

        return DB::transaction(function () use ($actor): array {
            PanelMutationLock::acquire();
            $session = $this->sessions->create($actor)['record'];
            $requestId = 'demo-content-'.Str::uuid();
            $report = [];
            $records = [];
            try {
                foreach (self::MODULES as $module => $definition) {
                    $model = $definition['model'];
                    for ($sequence = 1; $sequence <= count(self::VARIANTS) + 1; $sequence++) {
                        $variant = self::VARIANTS[$sequence - 2] ?? null;
                        $suffix = str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
                        $slug = 'tahlil-demo-'.$module.'-'.$suffix;
                        $record = $model::withTrashed()->where(function ($query) use ($slug, $module): void {
                            $query->where('slug', $slug);
                            if ($module === 'bonuses') {
                                $query->orWhere('meta_description', $slug);
                            }
                        })->first();
                        $created = $record === null;
                        if ($created) {
                            $categoryClass = CategoryAssignments::MODELS[$model];
                            $categories = $categoryClass::orderBy('id')->limit(2)->pluck('id')->all();
                            if ($categories === []) {
                                $categories[] = app($definition['manager'])->create($session, 'محتوای نمونه', $requestId)->id;
                            }
                            $payload = [
                                'title' => $variant === null ? $definition['title'].' ۰۰۱' : $definition['title'].' '.$variant['name'].'؛ '.$variant['topic'], 'slug' => $slug, 'status' => 'published',
                                'category_id' => $categories,
                                'description' => $variant === null ? 'محتوای آزمایشی برای بررسی نمایش کارت، تصویر، دسته‌بندی و جزئیات خدمات؛ این رکورد معرف کسب‌وکار واقعی نیست.' : $definition['title'].' '.$variant['name'].' با تمرکز بر '.$variant['topic'].'؛ '.$variant['detail'],
                                'content' => $this->content($definition['title'], $variant),
                                'meta_description' => $variant === null ? 'نمونه آزمایشی تحلیل برای بررسی نمایش محتوا و مشخصات خدمات در سایت.' : 'بررسی آزمایشی '.$variant['topic'].' در '.$definition['title'].' '.$variant['name'],
                            ];
                            if (isset($definition['specs'])) {
                                $payload += ['english_name' => 'Tahlil Demo '.ucwords(str_replace('-', ' ', $module)).' '.($variant['english'] ?? '001'),
                                    'logo' => self::IMAGE, 'website_url' => $variant === null ? 'https://example.com' : 'https://example.com/demo/'.$module.'/'.$suffix, 'founded_year' => (string) (1403 - $sequence),
                                    'specifications' => $this->specifications($definition['specs'], $sequence)];
                                if ($module === 'exchanges') {
                                    $payload += ['platform_type' => 'پلتفرم آزمایشی', 'markets' => $variant['topic'] ?? 'بازار نمونه', 'access' => $sequence % 2 === 0 ? 'وب و موبایل' : 'پنل وب', 'support' => 'پشتیبانی آزمایشی؛ پاسخ‌گویی در '.(8 + $sequence).' ساعت کاری'];
                                } elseif ($module === 'brokers') {
                                    $payload += ['platforms' => 'Demo '.($variant['english'] ?? 'Platform'), 'markets' => 'Forex؛ '.($variant['topic'] ?? 'بررسی خدمات'), 'access' => $sequence % 2 === 0 ? 'وب و موبایل' : 'پنل وب', 'account_currency' => ['USD', 'EUR', 'GBP'][$sequence % 3]];
                                }
                            } else {
                                $payload += ['cover_image' => self::IMAGE, 'reading_minutes' => 3 + (($sequence - 1) % 5)];
                                if ($module !== 'bonuses') {
                                    $payload['scheduled_for'] = null;
                                } else {
                                    $payload = [
                                        'provider_name' => $payload['title'], 'short_description' => $payload['description'],
                                        'content' => $payload['content'], 'cover_image' => self::IMAGE,
                                        'category_id' => $categories, 'is_published' => true,
                                        'amount' => 'پاداش آزمایشی '.$suffix, 'display_status' => 'نمونه',
                                        'website_url' => 'https://example.com/demo/bonuses/'.$suffix,
                                    ];
                                }
                            }
                            $result = app($definition['save'])->handle($session, null, $payload, $requestId);
                            $record = match ($module) {
                                'articles' => $result->article, 'news' => $result->newsItem, default => $result
                            };
                            if ($module === 'bonuses') {
                                $record->forceFill(['meta_description' => $slug])->save();
                            }
                        }
                        if (! isset($records[$module]) && ! $record->trashed() && $record->status === 'published') {
                            $records[$module] = $record;
                        }
                        $report[] = ['module' => $module, 'id' => $record->id, 'slug' => $record->slug, 'result' => $created ? 'created' : 'kept'];
                    }
                }
                $this->fillEmptySelections($session, $records, $requestId);
                $this->index->rebuild();
            } finally {
                $session->delete();
            }

            return $report;
        });
    }

    public function matchesPublishedDemo(string $module, Model $record): bool
    {
        $definition = self::MODULES[$module] ?? null;
        if ($definition === null || $record->status !== 'published' || $record->version !== 1 ||
            ! preg_match('/^tahlil-demo-'.preg_quote($module, '/').'-(\d{3})$/D', $record->slug, $match)) {
            return false;
        }
        $sequence = (int) $match[1];
        if ($sequence < 1 || $sequence > count(self::VARIANTS) + 1) {
            return false;
        }
        $variant = self::VARIANTS[$sequence - 2] ?? null;
        $title = $variant === null ? $definition['title'].' ۰۰۱' : $definition['title'].' '.$variant['name'].'؛ '.$variant['topic'];
        $description = $variant === null
            ? 'محتوای آزمایشی برای بررسی نمایش کارت، تصویر، دسته‌بندی و جزئیات خدمات؛ این رکورد معرف کسب‌وکار واقعی نیست.'
            : $definition['title'].' '.$variant['name'].' با تمرکز بر '.$variant['topic'].'؛ '.$variant['detail'];
        if ($record->title !== $title || $record->description !== $description ||
            ($record->getAttribute('logo') ?? $record->getAttribute('cover_image')) !== self::IMAGE) {
            return false;
        }
        $html = $this->content($definition['title'], $variant);
        $canonical = app(BlogHtmlSanitizer::class)->sanitize($html,
            config($module === 'news' ? 'news.trusted_media_hosts' : 'blog.trusted_media_hosts', []),
            rootAssetPrefixes: ['/images/', '/tahlilpanel/images/'], allowEmpty: true);

        return hash_equals($canonical, $record->content);
    }

    /** @param array{name: string, english: string, topic: string, detail: string, city: string}|null $variant */
    private function content(string $title, ?array $variant = null): string
    {
        $heading = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($variant !== null) {
            $name = htmlspecialchars($variant['name'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $topic = htmlspecialchars($variant['topic'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $detail = htmlspecialchars($variant['detail'], ENT_QUOTES | ENT_HTML5, 'UTF-8');

            return '<h2>'.$heading.' '.$name.'</h2><p>این نمونه از '.$heading.' برای ارزیابی '.$topic.' آماده شده است و معرف کسب‌وکار واقعی نیست.</p>'
                .'<p><img src="'.self::IMAGE.'" alt="نمودار آزمایشی '.$topic.'"></p>'
                .'<h2>بررسی '.$topic.'</h2><p>'.$detail.'</p>'
                .'<h3>سناریوی بررسی '.$name.'</h3><ol><li>شرایط '.$topic.' را در صفحه معرفی '.$name.' بخوانید.</li><li>یک درخواست آزمایشی ایجاد و نتیجه را ثبت کنید.</li><li>نتیجه را با اطلاعات اعلام‌شده در قرارداد مقایسه کنید.</li></ol>'
                .'<table><tbody><tr><th>بخش بررسی</th><th>شرح نمونه</th></tr><tr><td>'.$heading.'</td><td>'.$name.'</td></tr><tr><td>'.$topic.'</td><td>'.$detail.'</td></tr></tbody></table>'
                .'<blockquote>این داده آزمایشی برای ارزیابی '.$topic.' است و توصیه سرمایه‌گذاری یا تأیید خدمات '.$name.' محسوب نمی‌شود.</blockquote>';
        }

        return '<h2>'.$heading.'</h2><p>این متن صرفاً برای آزمودن نمایش محتوا در تحلیل تهیه شده است و توصیه سرمایه‌گذاری یا تأیید یک کسب‌وکار واقعی نیست.</p>'
            .'<p><img src="'.self::IMAGE.'" alt="نمودار نمونه خدمات مالی"></p>'
            .'<h2>چرا مقایسه اهمیت دارد</h2><p>انتخاب یک پلتفرم مالی تنها به ظاهر سایت محدود نمی‌شود. شرایط استفاده، شفافیت هزینه‌ها و راه‌های ارتباط با پشتیبانی را بررسی کنید.</p>'
            .'<h3>سه معیار برای بررسی اولیه</h3><ul><li>اطلاعات تماس و شرایط استفاده روشن باشد.</li><li>کارمزدها و محدودیت‌های خرید یا برداشت پیش از ثبت سفارش دیده شوند.</li><li>زمان پاسخ‌گویی و روش پیگیری درخواست‌ها مشخص باشد.</li></ul>'
            .'<table><tbody><tr><th>معیار</th><th>مقدار نمونه</th></tr><tr><td>شفافیت هزینه‌ها</td><td>بررسی صفحه تعرفه و هزینه نهایی سفارش</td></tr><tr><td>پشتیبانی</td><td>بررسی ساعت فعالیت و کانال‌های پاسخ‌گویی</td></tr></tbody></table>'
            .'<blockquote>اطلاعات این صفحه آزمایشی است. هیچ خدمت مالی واقعی از طریق این رکورد ارائه نمی‌شود.</blockquote>';
    }

    /** @param class-string $class
     * @return array<string, string> */
    private function specifications(string $class, int $sequence): array
    {
        $values = [
            'established_at' => '1402/04/04', 'head_office_address' => 'تهران، نشانی آزمایشی برای تست نمایش', 'physical_branch_count' => '3', 'years_active' => '3',
            'minimum_purchase' => '10 هزار تومان', 'maximum_purchase' => '100 میلیون تومان روزانه', 'minimum_sale' => '10 هزار تومان', 'maximum_sale' => '100 میلیون تومان روزانه',
            'minimum_deposit' => '100 دلار', 'maximum_withdrawal' => '1000 دلار در هر درخواست', 'identity_documents' => 'کارت ملی و شماره تلفن به نام کاربر',
            'physical_delivery_time' => '3 روز کاری برای تست', 'physical_delivery_method' => 'دریافت حضوری آزمایشی', 'asset_storage' => 'خزانه آزمایشی',
            'trading_fee' => '0.5 درصد', 'registration_fee' => 'صفر', 'evaluation_type' => 'دو مرحله‌ای', 'daily_loss_limit' => '5 درصد', 'total_loss_limit' => '10 درصد', 'payout_rules' => 'تسویه آزمایشی هر 14 روز',
        ];
        if ($sequence > 1) {
            $variant = self::VARIANTS[$sequence - 2];
            $values = array_replace($values, [
                'established_at' => (1403 - $sequence).'/'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT).'/15',
                'head_office_address' => $variant['city'].'، دفتر آزمایشی '.$variant['name'],
                'physical_branch_count' => (string) ($sequence - 1), 'years_active' => (string) $sequence,
                'minimum_purchase' => ($sequence * 10).' هزار تومان', 'maximum_purchase' => ($sequence * 20).' میلیون تومان روزانه',
                'minimum_sale' => ($sequence * 5).' هزار تومان', 'maximum_sale' => ($sequence * 15).' میلیون تومان روزانه',
                'minimum_deposit' => ($sequence * 50).' دلار', 'maximum_withdrawal' => ($sequence * 500).' دلار در هر درخواست',
                'trading_fee' => number_format($sequence / 10, 1, '.', '').' درصد',
                'physical_delivery_time' => $sequence.' روز کاری آزمایشی', 'asset_storage' => 'خزانه آزمایشی '.$variant['name'],
                'evaluation_type' => $sequence % 2 === 0 ? 'دو مرحله‌ای آزمایشی' : 'تک مرحله‌ای آزمایشی',
                'daily_loss_limit' => (2 + $sequence % 4).' درصد', 'total_loss_limit' => (6 + $sequence % 5).' درصد',
                'payout_rules' => 'تسویه آزمایشی هر '.(7 + $sequence).' روز',
            ]);
        }
        $specs = $class::empty();
        foreach ($specs as $field => $value) {
            $specs[$field] = $values[$field] ?? '';
        }
        foreach ($class::STATUS_FIELDS as $field) {
            $specs[$field] = in_array($field, ['in_person_visit', 'address_transparency', 'active_social_media', 'support_availability', 'trial_account', 'melted_gold_trading'], true) ? 'yes' : 'no';
        }

        return $specs;
    }

    /** @param array<string, Model> $records */
    private function fillEmptySelections(Models\PanelSession $session, array $records, string $requestId): void
    {
        $settings = Models\PageSetting::current();
        $snapshot = $settings->snapshot;
        $changed = false;
        foreach (['carousels', 'menu'] as $field) {
            foreach ($snapshot[$field] as &$row) {
                $module = $row['key'] === 'forex' ? 'brokers' : $row['key'];
                $record = $records[$module] ?? null;
                if ($row['selected_ids'] === [] && $record && ! $record->trashed() && $record->status === 'published') {
                    $row['selected_ids'] = [$record->id];
                    $changed = true;
                }
            }
            unset($row);
        }
        if ($changed) {
            $this->settings->handle($session, ['expected_version' => $settings->version, ...$snapshot], $requestId);
        }
    }
}
