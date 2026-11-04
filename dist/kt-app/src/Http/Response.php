<?php
declare(strict_types=1);

namespace KT\Http;

final class Response
{
    /** @param array<string,string> $headers */
    public function __construct(
        public int $status = 200,
        public string $body = '',
        public array $headers = []
    ) {
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            $status,
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ['Content-Type' => 'application/json; charset=utf-8']
        );
    }

    /** CSV with a UTF-8 BOM so Excel opens accents correctly. */
    public static function csv(string $filename, iterable $rows, string $sep = ','): self
    {
        // Quote only when needed (fputcsv also quotes fields containing spaces, which is noisy in headers).
        $enc = static function ($v) use ($sep): string {
            $v = (string) $v;
            return strpbrk($v, $sep . "\"\r\n") !== false ? '"' . str_replace('"', '""', $v) . '"' : $v;
        };
        $body = "\xEF\xBB\xBF";
        foreach ($rows as $row) {
            $body .= implode($sep, array_map($enc, $row)) . "\r\n";
        }
        return self::download($filename, $body, 'text/csv; charset=utf-8');
    }

    public static function download(string $filename, string $body, string $mime): self
    {
        $safe = str_replace(['"', "\r", "\n", '\\'], '', $filename);
        return new self(200, $body, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'attachment; filename="' . $safe . '"',
        ]);
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $k => $v) {
            header("$k: $v");
        }
        echo $this->body;
    }
}
