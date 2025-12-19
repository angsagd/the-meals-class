<?php
declare(strict_types=1);

namespace App\Model;

use DateTimeImmutable;
use RuntimeException;

final class Meal
{
    private int $id;
    private string $meal;
    private ?string $mealAlternate;
    private ?string $category;
    private ?string $area;
    private ?string $instructions;
    private ?string $mealThumb;
    private ?string $tags;
    private ?string $youtube;

    /**
     * Format:
     * [
     *   [
     *     'measure' => '1 tsp',
     *     'ingredient' => 'Salt',
     *     'image' => ['small'=>..., 'medium'=>..., 'large'=>...]
     *   ],
     *   ...
     * ]
     */
    private array $ingredients;

    private ?string $source;
    private ?string $imageSource;
    private ?string $creativeCommonsConfirmed;
    private ?DateTimeImmutable $modified;

    private bool $complete;

    /** @var null|callable(int): ?Meal */
    private $loader;

    private function __construct()
    {
        $this->ingredients = [];
        $this->complete = false;
        $this->loader = null;
        $this->mealAlternate = null;
        $this->category = null;
        $this->area = null;
        $this->instructions = null;
        $this->mealThumb = null;
        $this->tags = null;
        $this->youtube = null;
        $this->source = null;
        $this->imageSource = null;
        $this->creativeCommonsConfirmed = null;
        $this->modified = null;
    }

    public static function fromApiRowMinimal(array $row, callable $loader): self
    {
        $m = new self();
        $m->id = (int)($row['idMeal'] ?? 0);
        $m->meal = self::formatTitleCase((string)($row['strMeal'] ?? ''));
        $m->mealThumb = isset($row['strMealThumb']) ? (string)$row['strMealThumb'] : null;

        $m->complete = false;
        $m->loader = $loader;

        if ($m->id <= 0 || $m->meal === '') {
            throw new RuntimeException('Data minimal Meal tidak valid dari filter endpoint.');
        }

        return $m;
    }

    public static function fromApiRowFull(array $row, callable $loader): self
    {
        $m = new self();
        $m->id = (int)($row['idMeal'] ?? 0);
        $m->meal = self::formatTitleCase((string)($row['strMeal'] ?? ''));

        $m->mealAlternate = self::nullableString($row['strMealAlternate'] ?? null);
        $m->category = self::nullableTitleCase($row['strCategory'] ?? null);
        $m->area = self::nullableTitleCase($row['strArea'] ?? null);
        $m->instructions = self::nullableString($row['strInstructions'] ?? null);
        $m->mealThumb = self::nullableString($row['strMealThumb'] ?? null);
        $m->tags = self::nullableString($row['strTags'] ?? null);
        $m->youtube = self::nullableString($row['strYoutube'] ?? null);

        $m->ingredients = self::extractIngredients($row);

        $m->source = self::nullableString($row['strSource'] ?? null);
        $m->imageSource = self::nullableString($row['strImageSource'] ?? null);
        $m->creativeCommonsConfirmed = self::nullableString($row['strCreativeCommonsConfirmed'] ?? null);
        $m->modified = self::parseModified($row['dateModified'] ?? null);

        $m->complete = true;
        $m->loader = $loader;

        if ($m->id <= 0 || $m->meal === '') {
            throw new RuntimeException('Data Meal lengkap tidak valid dari lookup/search endpoint.');
        }

        return $m;
    }

    // =========================
    // Getter minimal (tanpa lookup)
    // =========================

    public function getId(): int
    {
        return $this->id;
    }

    public function getMeal(): string
    {
        return $this->meal;
    }

    public function getMealThumb(string $size = 'medium'): string
    {
        $thumb = $this->mealThumb ?? '';
        if ($thumb === '') {
            return '';
        }

        $size = strtolower(trim($size));
        return match ($size) {
            'small'  => rtrim($thumb, '/') . '/small',
            'large'  => rtrim($thumb, '/') . '/large',
            'medium', '' => rtrim($thumb, '/') . '/medium',
            default  => rtrim($thumb, '/') . '/medium',
        };
    }

    public function isComplete(): bool
    {
        return $this->complete;
    }

    // =========================
    // Getter lengkap (memicu lookup bila incomplete)
    // =========================

    public function getMealAlternate(): ?string
    {
        $this->ensureComplete();
        return $this->mealAlternate;
    }

    public function getCategory(): ?string
    {
        $this->ensureComplete();
        return $this->category;
    }

    public function getArea(): ?string
    {
        $this->ensureComplete();
        return $this->area;
    }

    public function getInstructions(): ?string
    {
        $this->ensureComplete();
        return $this->instructions;
    }

    public function getTags(): ?string
    {
        $this->ensureComplete();
        return $this->tags;
    }

    public function getYoutube(): ?string
    {
        $this->ensureComplete();
        return $this->youtube;
    }

    public function getIngredients(): array
    {
        $this->ensureComplete();
        return $this->ingredients;
    }

    public function getSource(): ?string
    {
        $this->ensureComplete();
        return $this->source;
    }

    public function getImageSource(): ?string
    {
        $this->ensureComplete();
        return $this->imageSource;
    }

    public function getCreativeCommonsConfirmed(): ?string
    {
        $this->ensureComplete();
        return $this->creativeCommonsConfirmed;
    }

    public function getModified(): ?DateTimeImmutable
    {
        $this->ensureComplete();
        return $this->modified;
    }

    // =========================
    // Lazy completion
    // =========================

    private function ensureComplete(): void
    {
        if ($this->complete) {
            return;
        }

        if (!is_callable($this->loader)) {
            throw new RuntimeException('Meal incomplete tidak memiliki loader untuk lookup.');
        }

        $loaded = ($this->loader)($this->id);

        if (!$loaded instanceof self) {
            throw new RuntimeException("Lookup gagal untuk melengkapi Meal id={$this->id}.");
        }

        // Hydrate dari hasil lookup (anggap hasil lengkap)
        $this->meal = $loaded->meal;
        $this->mealAlternate = $loaded->mealAlternate;
        $this->category = $loaded->category;
        $this->area = $loaded->area;
        $this->instructions = $loaded->instructions;
        $this->mealThumb = $loaded->mealThumb;
        $this->tags = $loaded->tags;
        $this->youtube = $loaded->youtube;
        $this->ingredients = $loaded->ingredients;
        $this->source = $loaded->source;
        $this->imageSource = $loaded->imageSource;
        $this->creativeCommonsConfirmed = $loaded->creativeCommonsConfirmed;
        $this->modified = $loaded->modified;

        $this->complete = true;
    }

    // =========================
    // Helper
    // =========================

    private static function extractIngredients(array $row): array
    {
        $result = [];

        for ($i = 1; $i <= 20; $i++) {
            $ingKey = 'strIngredient' . $i;
            $meaKey = 'strMeasure' . $i;

            $ingredientRaw = isset($row[$ingKey]) ? trim((string)$row[$ingKey]) : '';
            $measureRaw = isset($row[$meaKey]) ? trim((string)$row[$meaKey]) : '';

            if ($ingredientRaw === '') {
                continue;
            }

            $ingredient = self::formatTitleCase($ingredientRaw);
            $measure = $measureRaw === '' ? null : $measureRaw;

            // Nama file ingredient: lowercase + spasi jadi dash
            $fileStem = strtolower($ingredientRaw);
            $fileStem = preg_replace('/\s+/', '-', trim($fileStem)) ?? $fileStem;

            $base = 'https://www.themealdb.com/images/ingredients/' . $fileStem;

            $result[] = [
                'measure' => $measure ?? '',
                'ingredient' => $ingredient,
                'image' => [
                    'small'  => $base . '-small.png',
                    'medium' => $base . '-medium.png',
                    'large'  => $base . '-large.png',
                ],
            ];
        }

        return $result;
    }

    private static function parseModified(mixed $value): ?DateTimeImmutable
    {
        $s = self::nullableString($value);
        if ($s === null) {
            return null;
        }

        // dateModified kadang berupa "2015-09-16 10:34:21"
        $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $s);
        if ($dt instanceof DateTimeImmutable) {
            return $dt;
        }

        // fallback parse
        try {
            return new DateTimeImmutable($s);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function nullableString(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private static function formatTitleCase(string $s): string
    {
        $s = trim($s);
        if ($s === '') {
            return '';
        }

        $s = mb_strtolower($s);
        return mb_convert_case($s, MB_CASE_TITLE);
    }

    private static function nullableTitleCase(mixed $v): ?string
    {
        $s = self::nullableString($v);
        return $s === null ? null : self::formatTitleCase($s);
    }
}
