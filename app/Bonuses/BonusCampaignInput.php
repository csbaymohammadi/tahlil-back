<?php

namespace App\Bonuses;

use App\Models\BonusCampaign;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class BonusCampaignInput
{
    public const TYPES = ['signup', 'deposit', 'no_deposit', 'cashback', 'fee_discount', 'trading', 'referral', 'other'];

    public const DEFAULTS = [
        'type' => null,
        'reward' => ['kind' => null, 'amount' => null, 'percentage' => null, 'currency' => '', 'maximum_amount' => null, 'qualifier' => 'exact', 'custom_text' => ''],
        'eligibility' => ['audience' => 'unspecified', 'kyc_required' => null, 'iran_eligibility' => 'unspecified', 'country_restrictions' => '', 'minimum_deposit' => null, 'deposit_currency' => '', 'account_requirements' => ''],
        'conditions' => ['reward_withdrawable' => null, 'profit_withdrawable' => null, 'turnover_requirements' => '', 'usage_deadline' => '', 'withdrawal_requirements' => '', 'exclusions' => ''],
        'redemption' => ['url' => '', 'promo_code' => '', 'steps' => []],
        'source_url' => '',
        'reviewed_at' => null,
    ];

    public function __construct(private BonusMedia $media) {}

    /** @return array<string, mixed> */
    public function normalize(mixed $input): array
    {
        $snapshot = $this->object($input, [...self::DEFAULTS, 'provider' => null, 'availability' => 'unspecified', 'starts_at' => null, 'ends_at' => null], 'campaign');
        $snapshot['type'] = $this->option($snapshot['type'], [null, ...self::TYPES], 'campaign.type');
        $snapshot['availability'] = $this->option($snapshot['availability'], ['automatic', 'paused', 'ended', 'unspecified'], 'campaign.availability');
        $snapshot['provider'] = $this->provider($snapshot['provider']);
        $snapshot['reward'] = $this->reward($snapshot['reward']);
        $snapshot['eligibility'] = $this->eligibility($snapshot['eligibility'], $snapshot['type']);
        $snapshot['conditions'] = $this->conditions($snapshot['conditions']);
        $redemption = $this->object($snapshot['redemption'], self::DEFAULTS['redemption'], 'campaign.redemption');
        if (! is_array($redemption['steps']) || ! array_is_list($redemption['steps']) || count($redemption['steps']) > 20) {
            $this->invalid('campaign.redemption.steps', 'Supply up to 20 ordered instructions.');
        }
        foreach ($redemption['steps'] as $index => $step) {
            $redemption['steps'][$index] = $this->plain($step, 500, 'campaign.redemption.steps.'.$index);
            if ($redemption['steps'][$index] === '') {
                $this->invalid('campaign.redemption.steps.'.$index, 'Enter an instruction.');
            }
        }
        $redemption['promo_code'] = $this->plain($redemption['promo_code'], 200, 'campaign.redemption.promo_code');
        $redemption['url'] = $this->url($redemption['url'], 'campaign.redemption.url');
        $snapshot['redemption'] = $redemption;
        $snapshot['source_url'] = $this->url($snapshot['source_url'], 'campaign.source_url');
        foreach (['starts_at', 'ends_at', 'reviewed_at'] as $date) {
            $snapshot[$date] = $this->date($snapshot[$date], 'campaign.'.$date);
        }
        if ($snapshot['starts_at'] !== null && $snapshot['ends_at'] !== null && $snapshot['ends_at'] <= $snapshot['starts_at']) {
            $this->invalid('campaign.ends_at', 'The end must be after the start.');
        }

        return $snapshot;
    }

    /** @param array<string, mixed> $snapshot */
    public function validatePublication(array $snapshot, string $description, string $content): void
    {
        $required = [
            'description' => $description,
            'content' => $content,
            'campaign.provider' => $snapshot['provider'],
            'campaign.type' => $snapshot['type'],
            'campaign.reward.kind' => $snapshot['reward']['kind'],
            'campaign.source_url' => $snapshot['source_url'],
            'campaign.redemption.url' => $snapshot['redemption']['url'],
        ];
        if (($snapshot['provider']['kind'] ?? null) === 'independent') {
            $required['campaign.provider.name'] = $snapshot['provider']['name'];
        }
        if ($snapshot['availability'] === 'automatic') {
            $required['campaign.starts_at'] = $snapshot['starts_at'];
        }
        $reward = $snapshot['reward'];
        foreach (match ($reward['kind']) {
            'fixed' => ['amount', 'currency'],
            'percentage' => ['percentage'],
            'custom' => ['custom_text'],
            default => [],
        } as $field) {
            $required['campaign.reward.'.$field] = $reward[$field];
        }
        $errors = [];
        foreach ($required as $field => $value) {
            if ($value === null || $value === '') {
                $errors[$field] = ['This field is required to publish the bonus.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /** @param array<string, mixed> $reward */
    public static function amountDisplay(array $reward): string
    {
        return match ($reward['kind']) {
            'fixed' => $reward['amount'] === null || $reward['currency'] === '' ? '' :
                ($reward['qualifier'] === 'up_to' ? 'تا ' : '').$reward['amount'].' '.$reward['currency'],
            'percentage' => $reward['percentage'] === null ? '' :
                $reward['percentage'].'٪'.($reward['maximum_amount'] === null ? '' : ' تا سقف '.$reward['maximum_amount'].' '.$reward['currency']),
            'custom' => $reward['custom_text'],
            default => '',
        };
    }

    /** @return array<string, mixed>|null */
    private function provider(mixed $input): ?array
    {
        if ($input === null) {
            return null;
        }
        $kind = is_array($input) ? ($input['kind'] ?? null) : null;
        $fields = $kind === 'independent' ? ['name' => '', 'english_name' => '', 'logo' => ''] : [];
        $provider = $this->object($input, ['kind' => null, 'id' => null, ...$fields], 'campaign.provider');
        $this->option($kind, ['independent', ...array_keys(BonusCampaign::PROVIDERS)], 'campaign.provider.kind');
        if ($kind !== 'independent') {
            if (! is_int($provider['id']) || $provider['id'] < 1 || $provider['id'] > 9007199254740991) {
                $this->invalid('campaign.provider.id', 'Choose a positive integer provider ID.');
            }

            return $provider;
        }
        if ($provider['id'] !== null) {
            $this->invalid('campaign.provider.id', 'Independent providers do not have an ID.');
        }
        foreach (['name', 'english_name'] as $field) {
            $provider[$field] = $this->plain($provider[$field], 200, 'campaign.provider.'.$field);
        }
        $provider['logo'] = $this->plain($provider['logo'], 2048, 'campaign.provider.logo');
        if (! $this->media->approvedProviderLogo($provider['logo'])) {
            $this->invalid('campaign.provider.logo', 'Use an approved image URL.');
        }

        return $provider;
    }

    /** @return array<string, mixed> */
    private function reward(mixed $input): array
    {
        $path = 'campaign.reward';
        $reward = $this->object($input, self::DEFAULTS['reward'], $path);
        $kind = $this->option($reward['kind'], [null, 'fixed', 'percentage', 'custom'], $path.'.kind');
        foreach (['amount', 'percentage', 'maximum_amount'] as $field) {
            $reward[$field] = $this->decimal($reward[$field], $path.'.'.$field);
        }
        if ($reward['percentage'] !== null && (strlen(explode('.', $reward['percentage'])[0]) > 3 ||
            strcmp(str_pad(explode('.', $reward['percentage'])[0], 3, '0', STR_PAD_LEFT), '100') > 0 ||
            str_starts_with($reward['percentage'], '100.'))) {
            $this->invalid($path.'.percentage', 'The percentage must not exceed 100.');
        }
        $reward['currency'] = $this->currency($reward['currency'], $path.'.currency');
        $this->option($reward['qualifier'], ['exact', 'up_to'], $path.'.qualifier');
        $reward['custom_text'] = $this->plain($reward['custom_text'], 200, $path.'.custom_text');
        foreach (match ($kind) {
            'fixed' => ['percentage', 'maximum_amount', 'custom_text'],
            'percentage' => ['amount', 'custom_text', 'qualifier', ...($reward['maximum_amount'] === null ? ['currency'] : [])],
            'custom' => ['amount', 'percentage', 'maximum_amount', 'currency', 'qualifier'],
            default => [],
        } as $field) {
            if ($reward[$field] !== self::DEFAULTS['reward'][$field]) {
                $this->invalid($path.'.'.$field, 'This field does not apply to the selected reward.');
            }
        }
        if ($reward['maximum_amount'] !== null && $reward['currency'] === '') {
            $this->invalid($path.'.currency', 'Supply the currency for the monetary cap.');
        }

        return $reward;
    }

    /** @return array<string, mixed> */
    private function eligibility(mixed $input, ?string $type): array
    {
        $path = 'campaign.eligibility';
        $eligibility = $this->object($input, self::DEFAULTS['eligibility'], $path);
        $this->option($eligibility['audience'], ['new_users', 'existing_users', 'all_users', 'unspecified'], $path.'.audience');
        $this->option($eligibility['iran_eligibility'], ['eligible', 'ineligible', 'conditional', 'unspecified'], $path.'.iran_eligibility');
        $this->option($eligibility['kyc_required'], [true, false, null], $path.'.kyc_required');
        foreach (['country_restrictions', 'account_requirements'] as $field) {
            $eligibility[$field] = $this->plain($eligibility[$field], 2000, $path.'.'.$field);
        }
        $minimum = $eligibility['minimum_deposit'] = $this->decimal($eligibility['minimum_deposit'], $path.'.minimum_deposit', allowZero: true);
        $eligibility['deposit_currency'] = $this->currency($eligibility['deposit_currency'], $path.'.deposit_currency');
        if ($type === 'no_deposit' && $minimum !== null && $minimum !== '0') {
            $this->invalid($path.'.minimum_deposit', 'A no-deposit campaign cannot require a positive deposit.');
        }
        if ($minimum !== null && $eligibility['deposit_currency'] === '') {
            $this->invalid($path.'.deposit_currency', 'Supply the minimum deposit currency.');
        }

        return $eligibility;
    }

    /** @return array<string, mixed> */
    private function conditions(mixed $input): array
    {
        $path = 'campaign.conditions';
        $conditions = $this->object($input, self::DEFAULTS['conditions'], $path);
        foreach (self::DEFAULTS['conditions'] as $field => $default) {
            $conditions[$field] = $default === null ? $this->option($conditions[$field], [true, false, null], $path.'.'.$field)
                : $this->plain($conditions[$field], 2000, $path.'.'.$field);
        }

        return $conditions;
    }

    /** @param array<string, mixed> $defaults
     * @return array<string, mixed>
     */
    private function object(mixed $input, array $defaults, string $path): array
    {
        if (! is_array($input) || ($input !== [] && array_is_list($input))) {
            $this->invalid($path, 'Supply an object.');
        }
        foreach (array_diff_key($input, $defaults) as $field => $value) {
            $this->invalid($path.'.'.$field, 'This field is not accepted.');
        }

        return array_replace($defaults, $input);
    }

    private function plain(mixed $value, int $maximum, string $field): string
    {
        if (! is_string($value) || ! mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $maximum || strip_tags($value) !== $value || str_contains($value, "\0")) {
            $this->invalid($field, 'Enter plain text within the character limit.');
        }

        return trim($value);
    }

    /** @param list<string|bool|null> $choices */
    private function option(mixed $value, array $choices, string $field): mixed
    {
        if (! in_array($value, $choices, true)) {
            $this->invalid($field, 'Choose a supported value.');
        }

        return $value;
    }

    private function decimal(mixed $value, string $field, bool $allowZero = false): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^([0-9]{1,18})(?:\.([0-9]{1,8}))?$/D', $value, $parts)) {
            $this->invalid($field, 'Enter a decimal string with up to 18 integer and 8 fractional digits.');
        }
        $integer = ltrim($parts[1], '0') ?: '0';
        $fraction = rtrim($parts[2] ?? '', '0');
        if (! $allowZero && $integer === '0' && $fraction === '') {
            $this->invalid($field, 'The value must be positive.');
        }

        return $integer.($fraction === '' ? '' : '.'.$fraction);
    }

    private function currency(mixed $value, string $field): string
    {
        if (! is_string($value) || ($value !== '' && ! preg_match('/^[A-Z0-9]{2,16}$/D', $value))) {
            $this->invalid($field, 'Enter a currency code using 2–16 uppercase letters or digits.');
        }

        return $value;
    }

    private function date(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d{1,6})?(?:Z|\+00:00)$/D', $value, $parts) ||
            (int) $parts[1] < 1000 || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $this->invalid($field, 'Enter an ISO 8601 UTC timestamp.');
        }

        return CarbonImmutable::parse($value)->utc()->toISOString();
    }

    private function url(mixed $value, string $field): string
    {
        if ($value === '') {
            return '';
        }
        $url = $this->plain($value, 2048, $field);
        $parts = parse_url($url);
        if (! is_array($parts) || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) ||
            empty($parts['host']) || isset($parts['user']) || isset($parts['pass']) ||
            str_contains($url, '\\') || preg_match('~[\x00-\x20\x7f]|%(?![0-9A-Fa-f]{2})~', $url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->invalid($field, 'Enter a valid absolute HTTP or HTTPS URL without credentials.');
        }

        return $url;
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => [$message]]);
    }
}
