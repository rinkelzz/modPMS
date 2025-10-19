<?php

namespace ModPMS;

class RoomCategoryManager
{
    private string $storageFile;

    public function __construct(string $storageFile)
    {
        $this->storageFile = $storageFile;
        if (!file_exists($storageFile)) {
            $this->persist([]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        $raw = file_get_contents($this->storageFile);
        if ($raw === false || $raw === '') {
            return [];
        }

        $data = json_decode($raw, true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $category
     */
    public function add(array $category): void
    {
        $categories = $this->all();
        $category['id'] = $this->generateId($categories);
        $categories[] = $category;
        $this->persist($categories);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function persist(array $categories): void
    {
        $payload = json_encode($categories, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->storageFile, $payload . PHP_EOL);
    }

    /**
     * @param array<int, array<string, mixed>> $categories
     */
    private function generateId(array $categories): int
    {
        if (empty($categories)) {
            return 1;
        }

        $ids = array_column($categories, 'id');
        $max = max($ids);

        return is_numeric($max) ? ((int) $max + 1) : 1;
    }
}
