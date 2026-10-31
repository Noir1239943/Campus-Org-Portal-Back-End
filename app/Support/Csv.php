<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\StreamedResponse;

class Csv
{
    /**
     * @param  array<int, string>  $headers
     * @param  iterable<array<int, scalar|null>>  $rows
     */
    public static function download(string $filename, array $headers, iterable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            // UTF-8 BOM so Excel shows names like "Ybañez" correctly.
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headers);

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_bool($v) ? ($v ? 'Yes' : 'No') : $v, $row));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** Start of the current academic year (August 1). */
    public static function academicYearStart(): \Illuminate\Support\Carbon
    {
        $now = now();
        $year = $now->month >= 8 ? $now->year : $now->year - 1;

        return $now->copy()->setDate($year, 8, 1)->startOfDay();
    }
}
