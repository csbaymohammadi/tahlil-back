<?php

namespace App\Http\Controllers;

use App\Actions\PublicContent\ReadPublicDirectory;
use App\Actions\StockBrokers\ResolveLegacyStockRoute;
use App\Exceptions\PanelAuthException;
use App\Exchanges\ResolveExchangePublicItem;
use App\Http\Requests\LegacyStockRouteRequest;
use App\Http\Requests\PublicDirectoryRequest;
use App\Http\Resources\PublicDirectoryResource;
use App\PlatformProfiles\ResolvePlatformPublicItem;
use App\Support\PlatformProfileDefinition;
use App\Support\PublicReadSnapshot;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PublicDirectoryController extends Controller
{
    public function __construct(private PublicReadSnapshot $snapshot, private ReadPublicDirectory $archive, private ResolveExchangePublicItem $exchanges, private ResolvePlatformPublicItem $platforms) {}

    public function index(PublicDirectoryRequest $request, string $directory): Response
    {
        return $this->snapshot->run(function () use ($request, $directory): Response {
            $result = $this->archive->handle($directory, $request->validated());
            $records = $result['records'];
            unset($result['records']);

            return response()->json(['data' => PublicDirectoryResource::collection($records)->resolve($request), ...$result, 'request_id' => $request->attributes->get('request_id')]);
        });
    }

    public function show(Request $request, string $slug, string $directory): Response
    {
        return $this->read($request, $slug, $directory, false);
    }

    public function others(Request $request, string $slug, string $directory): Response
    {
        return $this->read($request, $slug, $directory, true);
    }

    public function legacy(LegacyStockRouteRequest $request, ResolveLegacyStockRoute $resolver): Response
    {
        return $this->snapshot->run(fn (): Response => response()->json(['data' => $resolver->handle($request->validated('source_path')), 'request_id' => $request->attributes->get('request_id')]));
    }

    private function read(Request $request, string $slug, string $directory, bool $others): Response
    {
        return $this->snapshot->run(function () use ($request, $slug, $directory, $others): Response {
            $result = $directory === 'exchanges' ? $this->exchanges->resolve($slug) : $this->platforms->resolve(match ($directory) {
                'brokers' => PlatformProfileDefinition::forex(), 'gold-platforms' => PlatformProfileDefinition::gold(), 'prop-firms' => PlatformProfileDefinition::propFirms(), default => PlatformProfileDefinition::stock()
            }, $slug, includeLegacy: false);
            if ($result['type'] === 'redirect') {
                $current = substr($result['path'], strlen('/'.$directory.'/'));

                return response('', 301)->header('Location', '/api/v1/'.$directory.'/'.rawurlencode($current).($others ? '/others' : ''));
            }
            $record = $result['exchange'] ?? $result['profile'] ?? null;
            if (! $record) {
                $code = match ($directory) {
                    'exchanges' => 'EXCHANGE_NOT_FOUND', 'brokers' => 'BROKER_NOT_FOUND', 'stock-brokers' => 'STOCK_BROKER_NOT_FOUND', 'gold-platforms' => 'GOLD_PLATFORM_NOT_FOUND', 'prop-firms' => 'PROP_FIRM_NOT_FOUND'
                };
                throw new PanelAuthException(404, $code, 'The public record was not found.');
            }
            if ($others) {
                $data = PublicDirectoryResource::collection($this->archive->others($directory, $record->id))->resolve($request);
            } else {
                $data = (new PublicDirectoryResource($record))->resolve($request);
            }

            return response()->json(['data' => $data, 'request_id' => $request->attributes->get('request_id')]);
        });
    }
}
