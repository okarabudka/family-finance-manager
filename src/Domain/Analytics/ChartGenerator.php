<?php

declare(strict_types=1);

namespace App\Domain\Analytics;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Generates PNG chart images via the Quickchart.io public API.
 *
 * Quickchart.io is a free (and self-hostable) Chart.js rendering service.
 * Charts are saved as temporary files and must be deleted by the caller after use.
 */
class ChartGenerator
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $storagePath,
    ) {}

    /**
     * Generates a doughnut (pie) chart and returns the path to the PNG file.
     *
     * @param string[] $labels
     * @param float[]  $data
     */
    public function generatePieChart(array $labels, array $data, string $title): string
    {
        $config = [
            'type' => 'doughnut',
            'data' => [
                'labels'   => $labels,
                'datasets' => [[
                    'data'            => $data,
                    'backgroundColor' => $this->generateColors(count($data)),
                ]],
            ],
            'options' => [
                'plugins' => [
                    'title'  => ['display' => true, 'text' => $title, 'font' => ['size' => 16]],
                    'legend' => ['position' => 'bottom'],
                ],
            ],
        ];

        return $this->requestChart($config, 600, 400);
    }

    /**
     * Generates a grouped bar chart (expenses vs income per label) and returns the PNG path.
     *
     * @param string[] $labels
     * @param float[]  $expenses
     * @param float[]  $income
     */
    public function generateBarChart(array $labels, array $expenses, array $income, string $title): string
    {
        $config = [
            'type' => 'bar',
            'data' => [
                'labels'   => $labels,
                'datasets' => [
                    ['label' => 'Expenses', 'data' => $expenses, 'backgroundColor' => '#EF4444'],
                    ['label' => 'Income',   'data' => $income,   'backgroundColor' => '#22C55E'],
                ],
            ],
            'options' => [
                'plugins' => [
                    'title' => ['display' => true, 'text' => $title],
                ],
                'scales' => [
                    'y' => ['beginAtZero' => true],
                ],
            ],
        ];

        return $this->requestChart($config, 700, 400);
    }

    /** @param array<string, mixed> $config */
    private function requestChart(array $config, int $width, int $height): string
    {
        $chartsDir = rtrim($this->storagePath, '/') . '/charts';
        if (!is_dir($chartsDir)) {
            mkdir($chartsDir, 0755, true);
        }

        $tmpFile = $chartsDir . '/' . bin2hex(random_bytes(16)) . '.png';

        try {
            $response = $this->httpClient->request('GET', 'https://quickchart.io/chart', [
                'query' => [
                    'c'               => json_encode($config, JSON_THROW_ON_ERROR),
                    'width'           => $width,
                    'height'          => $height,
                    'backgroundColor' => 'white',
                ],
            ]);

            if (file_put_contents($tmpFile, $response->getContent()) === false) {
                throw new \RuntimeException("Failed to write chart image to {$tmpFile}");
            }
        } catch (\Throwable $e) {
            // Do not leave a broken file on disk
            if (file_exists($tmpFile)) {
                unlink($tmpFile);
            }
            throw $e;
        }

        return $tmpFile;
    }

    /**
     * Returns a cyclic palette of hex colours with the given size.
     *
     * @return string[]
     */
    private function generateColors(int $count): array
    {
        $palette = [
            '#6366F1', '#F59E0B', '#EF4444', '#22C55E',
            '#3B82F6', '#EC4899', '#14B8A6', '#F97316',
            '#8B5CF6', '#06B6D4',
        ];

        $colors = [];
        for ($i = 0; $i < $count; $i++) {
            $colors[] = $palette[$i % count($palette)];
        }

        return $colors;
    }
}
