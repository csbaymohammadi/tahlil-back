<?php

namespace App\Models;

use App\Support\PanelMutationLock;
use App\Support\RoleNames;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

#[Fillable(['name'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    protected $attributes = ['version' => 1];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function save(array $options = []): bool
    {
        $this->name = Str::trim($this->name);
        $this->name_normalized = RoleNames::normalize($this->name);

        return DB::transaction(function () use ($options): bool {
            PanelMutationLock::acquire();
            if ($this->exists && $this->isDirty('name')) {
                $this->version = $this->getOriginal('version') + 1;
            }

            return parent::save($options);
        });
    }

    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            PanelMutationLock::acquire();
            DB::table('users')->where('role_id', $this->id)->whereNull('deleted_at')
                ->update(['role_id' => null, 'version' => DB::raw('version + 1'), 'updated_at' => now()]);
            $this->version++;
            $this->save();

            return parent::delete();
        });
    }

    protected function casts(): array
    {
        return ['version' => 'integer'];
    }
}
