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
    public static function csv(string $filename, iterable $rows): self
    {
        $fh = fopen('php://memory', 'w+');
        foreach ($rows as $row) {
            fputcsv($fh, $row, ',', '"', '', "\r\n");
        }
        rewind($fh);
        $body = "\xEF\xBB\xBF" . stream_get_contents($fh);
        fclose($fh);
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
