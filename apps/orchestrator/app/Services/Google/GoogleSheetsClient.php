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
        $response = $this->request()
            ->get($this->valuesUrl($columnsRange))
            ->throw()
            ->json();

        return $response['values'] ?? [];
    }

    public function clearRows(string $columnsRange): void
    {
        $this->request()
            ->post($this->valuesUrl($columnsRange).':clear', [])
            ->throw();
    }

    public function writeRows(string $startCellRange, array $rows): void
    {
        $this->request()
            ->put($this->valuesUrl($startCellRange), [
                'range' => $this->sheetRange($startCellRange),
                'majorDimension' => 'ROWS',
                'values' => $rows,
            ])
            ->throw();
    }

    private function request()
    {
        return Http::withToken($this->accessTokenProvider->getAccessToken())
            ->acceptJson()
            ->asJson()
            ->withQueryParameters([
                'valueInputOption' => 'USER_ENTERED',
            ]);
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
