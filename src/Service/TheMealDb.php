<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Meal;
use RuntimeException;

final class TheMealDb
{
    private string $baseUrl;
    private string $output;
    private string $version;
    private string $apiKey;

    private int $timeoutSeconds;
    private int $connectTimeoutSeconds;
    private int $maxRetries;
    private string $userAgent;

    public function __construct(
        string $apiKey = '1',
        string $output = 'json',
        string $version = 'v1',
        string $apiBaseUrl = 'https://www.themealdb.com/api',
        int $timeoutSeconds = 10,
        int $connectTimeoutSeconds = 5,
        int $maxRetries = 2,
        ?string $userAgent = null
    ) {
        $this->apiKey = $apiKey;
        $this->output = $output;
        $this->version = $version;
        $this->baseUrl = rtrim($apiBaseUrl, '/') . '/' . trim($output, '/') . '/' . trim($version, '/') . '/' . trim($apiKey, '/') . '/';

        $this->timeoutSeconds = $timeoutSeconds;
        $this->connectTimeoutSeconds = $connectTimeoutSeconds;
        $this->maxRetries = max(0, $maxRetries);
        $this->userAgent = $userAgent ?? 'PHP-TheMealDB-Client/1.0';
    }

    /** Search meal by name with keyword: search.php?s=[keyword] */
    public function searchByName(string $keyword): array
    {
        $data = $this->request('search.php', ['s' => $keyword]);
        if (!isset($data['meals']) || $data['meals'] === null) {
            return [];
        }
        return $this->mapMealsFull($data['meals']);
    }

    /** List all meals by first letter: search.php?f=[firstLetter] */
    public function listByFirstLetter(string $firstLetter): array
    {
        $letter = mb_substr(trim($firstLetter), 0, 1);
        $data = $this->request('search.php', ['f' => $letter]);
        if (!isset($data['meals']) || $data['meals'] === null) {
            return [];
        }
        return $this->mapMealsFull($data['meals']);
    }

    /** Lookup full meal details by id: lookup.php?i=[id] */
    public function lookupById(int $id): ?Meal
    {
        $data = $this->request('lookup.php', ['i' => (string)$id]);
        if (!isset($data['meals']) || $data['meals'] === null || !is_array($data['meals']) || count($data['meals']) === 0) {
            return null;
        }
        return $this->mapMealFull($data['meals'][0]);
    }

    /** Lookup a single random meal: random.php */
    public function random(): ?Meal
    {
        $data = $this->request('random.php');
        if (!isset($data['meals']) || $data['meals'] === null || !is_array($data['meals']) || count($data['meals']) === 0) {
            return null;
        }
        return $this->mapMealFull($data['meals'][0]);
    }

    /** List all meal categories: categories.php (mengembalikan array asosiatif mentah) */
    public function categories(): array
    {
        $data = $this->request('categories.php');
        return $data['categories'] ?? [];
    }

    /**
     * List all Categories/Area/Ingredients (list.php?c=list | a=list | i=list)
     * $type: "category"|"area"|"ingredient" (default: "category")
     * Jika tidak sesuai: return []
     */
    public function list(string $type = 'category'): array
    {
        $type = strtolower(trim($type));
        $paramKey = match ($type) {
            'category'   => 'c',
            'area'       => 'a',
            'ingredient' => 'i',
            default      => null,
        };

        if ($paramKey === null) {
            return [];
        }

        $data = $this->request('list.php', [$paramKey => 'list']);

        // Struktur respons berbeda-beda: biasanya "meals": [...]
        if (!isset($data['meals']) || $data['meals'] === null) {
            return [];
        }

        return $data['meals'];
    }

    /** Filter by main ingredient: filter.php?i=[ingredient] => Meal minimal (incomplete) */
    public function filterByIngredient(string $ingredient): array
    {
        $data = $this->request('filter.php', ['i' => $ingredient]);
        return $this->mapMealsMinimalFromFilter($data);
    }

    /** Filter by Category: filter.php?c=[category] => Meal minimal (incomplete) */
    public function filterByCategory(string $category): array
    {
        $data = $this->request('filter.php', ['c' => $category]);
        return $this->mapMealsMinimalFromFilter($data);
    }

    /** Filter by Area: filter.php?a=[area] => Meal minimal (incomplete) */
    public function filterByArea(string $area): array
    {
        $data = $this->request('filter.php', ['a' => $area]);
        return $this->mapMealsMinimalFromFilter($data);
    }

    // =========================
    // Internal
    // =========================

    private function request(string $endpoint, array $query = []): array
    {
        $url = $this->baseUrl . ltrim($endpoint, '/');
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        $lastError = null;

        while ($attempt <= $this->maxRetries) {
            $attempt++;

            $ch = curl_init($url);
            if ($ch === false) {
                throw new RuntimeException('Gagal inisialisasi cURL.');
            }

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ],
            ]);

            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($errno !== 0) {
                $lastError = "cURL error ({$errno}): {$error}";
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                $lastError = "HTTP error: {$httpCode}";
            } elseif (!is_string($raw) || $raw === '') {
                $lastError = 'Respons kosong dari server.';
            } else {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $lastError = 'JSON tidak valid.';
                } else {
                    return $decoded;
                }
            }

            // Backoff sederhana sebelum retry
            if ($attempt <= $this->maxRetries) {
                usleep(150_000 * $attempt); // 150ms, 300ms, ...
            }
        }

        throw new RuntimeException("Request gagal: {$url}. Penyebab terakhir: {$lastError}");
    }

    private function mapMealsFull(array $rows): array
    {
        $meals = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $meals[] = $this->mapMealFull($row);
            }
        }
        return $meals;
    }

    private function mapMealFull(array $row): Meal
    {
        // loader untuk lazy-load tidak diperlukan karena sudah lengkap, tapi tetap disiapkan
        $loader = function (int $id): ?Meal {
            return $this->lookupById($id);
        };

        return Meal::fromApiRowFull($row, $loader);
    }

    private function mapMealsMinimalFromFilter(array $data): array
    {
        if (!isset($data['meals']) || $data['meals'] === null) {
            return [];
        }

        $rows = $data['meals'];
        if (!is_array($rows)) {
            return [];
        }

        $loader = function (int $id): ?Meal {
            return $this->lookupById($id);
        };

        $meals = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $meals[] = Meal::fromApiRowMinimal($row, $loader);
        }
        return $meals;
    }
}
