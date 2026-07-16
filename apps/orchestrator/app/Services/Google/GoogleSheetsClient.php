<?php

namespace App\Services\Google;

use Illuminate\Support\Facades\Http;

class GoogleSheetsClient
{
    public function __construct(
        private readonly GoogleAccessTokenProvider $accessTokenProvider,
        private readonly string $spreadsheetId,
        private readonly string $worksheetName,
    ) {
    }

    public function fetchRows(string $columnsRange): array
    {
        $response = Http::withToken($this->accessTokenProvider->getAccessToken())
            ->acceptJson()
            ->get($this->valuesUrl($columnsRange))
            ->throw()
            ->json();

        return $response['values'] ?? [];
    }

    public function clearRows(string $columnsRange): void
    {
        Http::withToken($this->accessTokenProvider->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->post($this->valuesUrl($columnsRange).':clear', (object) [])
            ->throw();
    }

    public function writeRows(string $startCellRange, array $rows): void
    {
        Http::withToken($this->accessTokenProvider->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->send('PUT', $this->valuesUrl($startCellRange), [
                'query' => [
                    'valueInputOption' => 'USER_ENTERED',
                ],
                'json' => [
                    'range' => $this->sheetRange($startCellRange),
                    'majorDimension' => 'ROWS',
                    'values' => $rows,
                ],
            ])
            ->throw();
    }

    private function valuesUrl(string $range): string
    {
        $encodedRange = rawurlencode($this->sheetRange($range));

        return "https://sheets.googleapis.com/v4/spreadsheets/{$this->spreadsheetId}/values/{$encodedRange}";
    }

    private function sheetRange(string $range): string
    {
        return "'{$this->worksheetName}'!{$range}";
    }
}
