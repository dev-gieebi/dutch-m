<?php

namespace App\Support;

use Illuminate\Http\Response;

/** Sérialisation / analyse CSV (RFC 4180, séparateur « ; » ou « , »). */
final class Csv
{
    public static function make(array $headers, array $rows, string $sep = ';'): string
    {
        $esc = function ($v) {
            if ($v === null) {
                return '';
            }
            if ($v instanceof \DateTimeInterface) {
                $v = Format::iso(\Illuminate\Support\Carbon::instance($v));
            }
            if (is_bool($v)) {
                $v = $v ? 'true' : 'false';
            }
            $s = (string) $v;

            return preg_match('/[";\n\r,]/', $s) ? '"'.str_replace('"', '""', $s).'"' : $s;
        };
        $lines = array_map(fn ($r) => implode($sep, array_map($esc, $r)), [$headers, ...$rows]);

        return "\u{FEFF}".implode("\r\n", $lines);
    }

    public static function download(string $content, string $filename): Response
    {
        return new Response($content, 200, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /** @return list<array<string, string>> */
    public static function parse(string $content): array
    {
        $text = preg_replace('/^\x{FEFF}/u', '', $content);
        $firstLine = preg_split('/\r?\n/', $text, 2)[0] ?? '';
        $sep = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        $row = [];
        $field = '';
        $inQuotes = false;
        $len = strlen($text);
        for ($i = 0; $i < $len; $i++) {
            $ch = $text[$i];
            if ($inQuotes) {
                if ($ch === '"' && ($text[$i + 1] ?? '') === '"') {
                    $field .= '"';
                    $i++;
                } elseif ($ch === '"') {
                    $inQuotes = false;
                } else {
                    $field .= $ch;
                }
            } elseif ($ch === '"') {
                $inQuotes = true;
            } elseif ($ch === $sep) {
                $row[] = $field;
                $field = '';
            } elseif ($ch === "\n" || $ch === "\r") {
                if ($ch === "\r" && ($text[$i + 1] ?? '') === "\n") {
                    $i++;
                }
                $row[] = $field;
                $rows[] = $row;
                $row = [];
                $field = '';
            } else {
                $field .= $ch;
            }
        }
        if ($field !== '' || $row) {
            $row[] = $field;
            $rows[] = $row;
        }
        $rows = array_values(array_filter($rows, fn ($r) => count(array_filter($r, fn ($c) => trim($c) !== '')) > 0));
        if (! $rows) {
            return [];
        }
        $headers = array_map('trim', array_shift($rows));

        return array_map(function ($r) use ($headers) {
            $o = [];
            foreach ($headers as $i => $h) {
                $o[$h] = trim($r[$i] ?? '');
            }

            return $o;
        }, $rows);
    }
}
