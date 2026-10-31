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
                    $slug = 'tahlil-demo-'.$module.'-001';
                    $record = $model::withTrashed()->where('slug', $slug)->first();
                    $created = $record === null;
                    if ($created) {
                        $categoryClass = CategoryAssignments::MODELS[$model];
                        $categories = $categoryClass::orderBy('id')->limit(2)->pluck('id')->all();
                        if ($categories === []) {
                            $categories[] = app($definition['manager'])->create($session, 'محتوای نمونه', $requestId)->id;
                        }
                        $payload = [
                            'title' => $definition['title'].' ۰۰۱', 'slug' => $slug, 'status' => 'published',
                            'category_id' => $categories,
                            'description' => 'محتوای آزمایشی برای بررسی نمایش کارت، تصویر، دسته‌بندی و جزئیات خدمات؛ این رکورد معرف کسب‌وکار واقعی نیست.',
                            'content' => $this->content($definition['title']),
                            'meta_description' => 'نمونه آزمایشی تحلیل برای بررسی نمایش محتوا و مشخصات خدمات در سایت.',
                        ];
                        if (isset($definition['specs'])) {
                            $payload += ['english_name' => 'Tahlil Demo '.ucwords(str_replace('-', ' ', $module)).' 001',
                                'logo' => self::IMAGE, 'website_url' => 'https://example.com', 'founded_year' => '1402',
                                'specifications' => $this->specifications($definition['specs'])];
                            if ($module === 'exchanges') {
                                $payload += ['platform_type' => 'پلتفرم آزمایشی', 'markets' => 'بازار نمونه', 'access' => 'وب و موبایل', 'support' => 'پشتیبانی آزمایشی'];
                            } elseif ($module === 'brokers') {
                                $payload += ['platforms' => 'پلتفرم معاملاتی نمونه', 'markets' => 'Forex', 'access' => 'وب و موبایل', 'account_currency' => 'USD'];
                            }
                        } else {
                            $payload += ['cover_image' => self::IMAGE, 'reading_minutes' => 3];
                            if ($module !== 'bonuses') {
                                $payload['scheduled_for'] = null;
                            }
                        }
                        $result = app($definition['save'])->handle($session, null, $payload, $requestId);
                        $record = match ($module) {
                            'articles' => $result->article, 'news' => $result->newsItem, default => $result
                        };
                    }
                    $records[$module] = $record;
                    $report[] = ['module' => $module, 'id' => $record->id, 'slug' => $record->slug, 'result' => $created ? 'created' : 'kept'];
                }
                $this->fillEmptySelections($session, $records, $requestId);
                $this->index->rebuild();
            } finally {
                $session->delete();
            }

            return $report;
        });
    }

    private function content(string $title): string
    {
        $heading = htmlspecialchars($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<h2>'.$heading.'</h2><p>این متن صرفاً برای آزمودن نمایش محتوا در تحلیل تهیه شده است و توصیه سرمایه‌گذاری یا تأیید یک کسب‌وکار واقعی نیست.</p>'
            .'<p><img src="'.self::IMAGE.'" alt="نمودار نمونه خدمات مالی"></p>'
            .'<h2>چرا مقایسه اهمیت دارد</h2><p>انتخاب یک پلتفرم مالی تنها به ظاهر سایت محدود نمی‌شود. شرایط استفاده، شفافیت هزینه‌ها و راه‌های ارتباط با پشتیبانی را بررسی کنید.</p>'
            .'<h3>سه معیار برای بررسی اولیه</h3><ul><li>اطلاعات تماس و شرایط استفاده روشن باشد.</li><li>کارمزدها و محدودیت‌های خرید یا برداشت پیش از ثبت سفارش دیده شوند.</li><li>زمان پاسخ‌گویی و روش پیگیری درخواست‌ها مشخص باشد.</li></ul>'
            .'<table><tbody><tr><th>معیار</th><th>مقدار نمونه</th></tr><tr><td>شفافیت هزینه‌ها</td><td>بررسی صفحه تعرفه و هزینه نهایی سفارش</td></tr><tr><td>پشتیبانی</td><td>بررسی ساعت فعالیت و کانال‌های پاسخ‌گویی</td></tr></tbody></table>'
            .'<blockquote>اطلاعات این صفحه آزمایشی است. هیچ خدمت مالی واقعی از طریق این رکورد ارائه نمی‌شود.</blockquote>';
    }

    /** @param class-string $class
     * @return array<string, string> */
    private function specifications(string $class): array
    {
        $values = [
            'established_at' => '1402/04/04', 'head_office_address' => 'تهران، نشانی آزمایشی برای تست نمایش', 'physical_branch_count' => '3', 'years_active' => '3',
            'minimum_purchase' => '10 هزار تومان', 'maximum_purchase' => '100 میلیون تومان روزانه', 'minimum_sale' => '10 هزار تومان', 'maximum_sale' => '100 میلیون تومان روزانه',
            'minimum_deposit' => '100 دلار', 'maximum_withdrawal' => '1000 دلار در هر درخواست', 'identity_documents' => 'کارت ملی و شماره تلفن به نام کاربر',
            'physical_delivery_time' => '3 روز کاری برای تست', 'physical_delivery_method' => 'دریافت حضوری آزمایشی', 'asset_storage' => 'خزانه آزمایشی',
            'trading_fee' => '0.5 درصد', 'registration_fee' => 'صفر', 'evaluation_type' => 'دو مرحله‌ای', 'daily_loss_limit' => '5 درصد', 'total_loss_limit' => '10 درصد', 'payout_rules' => 'تسویه آزمایشی هر 14 روز',
        ];
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
