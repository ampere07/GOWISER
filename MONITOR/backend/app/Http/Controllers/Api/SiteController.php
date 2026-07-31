<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Connector\ConnectionManager;
use Illuminate\Http\Request;

/**
 * What the portal can see: which sites are connected, and which canonical
 * datasets each one maps.
 *
 * The frontend builds its navigation from this, so a site that has not mapped
 * expenses never shows a profit and loss tab.
 */
class SiteController extends Controller
{
    public function __construct(private ConnectionManager $connections)
    {
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $allowed = $user?->allowedSites();

        $sites = [];

        foreach ($this->connections->sites() as $key => $site) {
            // Role-level site scoping: an area manager sees their branches only.
            if ($allowed !== null && !in_array($key, $allowed, true)) {
                continue;
            }

            $datasets = [];
            $error = null;

            try {
                $datasets = $this->connections->datasetsFor($key);
            } catch (\Throwable $e) {
                report($e);
                $error = config('app.debug') ? $e->getMessage() : 'Mapping could not be resolved.';
            }

            $sites[] = [
                'key' => $key,
                'label' => $site->label,
                'profile' => $site->profile_key,
                'datasets' => $datasets,
                'areas' => $this->areasFor($datasets),
                'status' => $site->last_status,
                'error' => $error,
            ];
        }

        return response()->json([
            'status' => 'success',
            'data' => [
                'sites' => $sites,
                'default' => $sites[0]['key'] ?? null,
            ],
        ]);
    }

    /**
     * Which dashboard areas the site's mapped datasets unlock, so the sidebar
     * does not have to know the dataset-to-area relationship.
     */
    private function areasFor(array $datasets): array
    {
        $areas = [];

        foreach ($datasets as $dataset) {
            $area = config("datasets.{$dataset}.area");

            if ($area && !in_array($area, $areas, true)) {
                $areas[] = $area;
            }
        }

        return $areas;
    }
}
