<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond([
        'success' => false,
        'error' => 'Method not allowed. Use POST.'
    ], 405);
}

try {
    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {
        respond([
            'success' => false,
            'error' => 'Request body kosong.'
        ], 400);
    }

    $input = json_decode($raw, true);

    if (!is_array($input)) {
        respond([
            'success' => false,
            'error' => 'JSON request tidak valid.'
        ], 400);
    }

    $payload = trim((string)($input['qris_payload'] ?? ''));

    if ($payload === '') {
        respond([
            'success' => false,
            'error' => 'qris_payload wajib diisi.'
        ], 400);
    }

    if (strlen($payload) < 20) {
        respond([
            'success' => false,
            'error' => 'Payload QRIS terlalu pendek atau tidak valid.'
        ], 400);
    }

    /*
     * Data dari frontend
     */
    $merchantName = trim((string)($input['merchant_name'] ?? ''));
    $cityName     = trim((string)($input['city_name'] ?? ''));
    $postalCode   = trim((string)($input['postal_code'] ?? ''));

    $amount = normalizeAmount($input['amount'] ?? 0);

    $feeType = strtolower(trim((string)($input['fee_type'] ?? 'none')));
    $feeValue = normalizeNumber($input['fee_value'] ?? 0);

    if (!in_array($feeType, ['none', 'fixed', 'percent'], true)) {
        respond([
            'success' => false,
            'error' => 'fee_type tidak valid.'
        ], 400);
    }

    if ($feeValue < 0) {
        respond([
            'success' => false,
            'error' => 'fee_value tidak valid.'
        ], 400);
    }

    if ($feeType === 'percent' && $feeValue > 100) {
        respond([
            'success' => false,
            'error' => 'Persentase biaya maksimal 100%.'
        ], 400);
    }

    /*
     * Parse QRIS asli
     */
    $original = parseQris($payload);

    if (!$original['valid']) {
        respond([
            'success' => false,
            'error' => 'Payload QRIS tidak valid atau CRC tidak sesuai.'
        ], 400);
    }

    /*
     * Jika merchant/city/postal kosong,
     * gunakan data original.
     */
    $finalMerchant = $merchantName !== ''
        ? $merchantName
        : $original['merchant_name'];

    $finalCity = $cityName !== ''
        ? $cityName
        : $original['city_name'];

    $finalPostal = $postalCode !== ''
        ? $postalCode
        : $original['postal_code'];

    /*
     * Jika amount = 0,
     * QRIS tidak memiliki nominal tertentu.
     */
    $baseAmount = $amount;

    /*
     * Hitung biaya layanan.
     */
    $serviceFee = 0;

    if ($baseAmount > 0 && $feeType !== 'none') {

        if ($feeType === 'fixed') {
            $serviceFee = $feeValue;
        }

        if ($feeType === 'percent') {
            $serviceFee = $baseAmount * ($feeValue / 100);
        }
    }

    /*
     * QRIS menggunakan nominal rupiah.
     * Bulatkan biaya ke rupiah terdekat.
     */
    $serviceFee = round($serviceFee);

    $totalAmount = $baseAmount > 0
        ? $baseAmount + $serviceFee
        : 0;

    /*
     * Buat payload baru.
     */
    $modifiedPayload = modifyQris(
        $payload,
        $finalMerchant,
        $finalCity,
        $finalPostal,
        $baseAmount,
        $feeType,
        $feeValue
    );

    /*
     * Generate QR menggunakan QRServer
     */
    $qrUrl =
        'https://api.qrserver.com/v1/create-qr-code/' .
        '?size=500x500' .
        '&margin=10' .
        '&data=' . rawurlencode($modifiedPayload);

    /*
     * Response dibuat sama dengan format
     * yang digunakan frontend.
     */
    $response = [
        'success' => true,
        'data' => [
            'original' => [
                'payload' => $payload,
                'merchant_name' => $original['merchant_name'],
                'city_name' => $original['city_name'],
                'postal_code' => $original['postal_code'],
                'nmid' => $original['nmid'],
                'is_dynamic' => $original['is_dynamic'],
                'amount' => $original['amount']
            ],

            'modified' => [
                'payload' => $modifiedPayload,
                'merchant_name' => $finalMerchant,
                'city_name' => $finalCity,
                'postal_code' => $finalPostal,
                'nmid' => $original['nmid'],
                'is_dynamic' => $original['is_dynamic'],
                'amount' => $baseAmount,

                'base_amount' => $baseAmount,
                'service_fee' => $serviceFee,
                'total_amount' => $totalAmount,

                'fee_type' => $feeType,
                'fee_value' => $feeValue
            ],

            'qr_code_url' => $qrUrl
        ]
    ];

    respond($response, 200);

} catch (Throwable $e) {

    respond([
        'success' => false,
        'error' => $e->getMessage()
    ], 500);
}


/*
|--------------------------------------------------------------------------
| RESPONSE
|--------------------------------------------------------------------------
*/

function respond(array $data, int $status = 200): void
{
    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


/*
|--------------------------------------------------------------------------
| NUMBER HELPERS
|--------------------------------------------------------------------------
*/

function normalizeNumber($value): float
{
    if (is_string($value)) {
        $value = str_replace(',', '.', trim($value));
    }

    if (!is_numeric($value)) {
        return 0;
    }

    return (float)$value;
}

function normalizeAmount($value): float
{
    $value = normalizeNumber($value);

    if ($value < 0) {
        return 0;
    }

    return round($value);
}


/*
|--------------------------------------------------------------------------
| QRIS TLV PARSER
|--------------------------------------------------------------------------
|
| QRIS menggunakan format:
|
| ID  + LENGTH + VALUE
|
| Contoh:
|
| 59 14 ARJUNA BINTANG
|
|--------------------------------------------------------------------------
*/

function parseTlv(string $payload): array
{
    $items = [];

    $length = strlen($payload);
    $offset = 0;

    while ($offset < $length) {

        if ($offset + 4 > $length) {
            throw new Exception('Struktur TLV QRIS tidak valid.');
        }

        $id = substr($payload, $offset, 2);
        $lenText = substr($payload, $offset + 2, 2);

        if (!ctype_digit($id) || !ctype_digit($lenText)) {
            throw new Exception('Tag QRIS tidak valid.');
        }

        $valueLength = (int)$lenText;

        $valueStart = $offset + 4;

        if ($valueStart + $valueLength > $length) {
            throw new Exception('Panjang data QRIS tidak sesuai.');
        }

        $value = substr(
            $payload,
            $valueStart,
            $valueLength
        );

        $items[] = [
            'id' => $id,
            'value' => $value
        ];

        $offset = $valueStart + $valueLength;
    }

    return $items;
}


/*
|--------------------------------------------------------------------------
| FIND TLV
|--------------------------------------------------------------------------
*/

function getTag(array $items, string $id): ?string
{
    foreach ($items as $item) {
        if ($item['id'] === $id) {
            return $item['value'];
        }
    }

    return null;
}


/*
|--------------------------------------------------------------------------
| MERCHANT ACCOUNT INFO
|--------------------------------------------------------------------------
|
| Cari NMID dari data ID.CO.QRIS.WWW
|--------------------------------------------------------------------------
*/

function findNmid(array $items): string
{
    foreach ($items as $item) {

        /*
         * Merchant Account Information biasanya
         * berada pada tag 26-51.
         */
        if ((int)$item['id'] < 26 || (int)$item['id'] > 51) {
            continue;
        }

        try {
            $children = parseTlv($item['value']);

            foreach ($children as $child) {

                /*
                 * 00 biasanya GUI.
                 */
                if ($child['id'] === '00') {
                    continue;
                }

                /*
                 * Cari ID CO QRIS.
                 */
                if (
                    stripos($child['value'], 'ID.CO.QRIS.WWW') !== false
                ) {
                    foreach ($children as $nested) {

                        if (
                            $nested['id'] === '02' &&
                            preg_match('/^ID[0-9A-Z]+$/', $nested['value'])
                        ) {
                            return $nested['value'];
                        }
                    }
                }
            }

        } catch (Throwable $e) {
            continue;
        }
    }

    return '';
}


/*
|--------------------------------------------------------------------------
| PARSE QRIS
|--------------------------------------------------------------------------
*/

function parseQris(string $payload): array
{
    $items = parseTlv($payload);

    $merchantName = getTag($items, '59') ?? '';
    $cityName     = getTag($items, '60') ?? '';
    $postalCode   = getTag($items, '61') ?? '';

    $amountValue = getTag($items, '54');

    $amount = 0;

    if ($amountValue !== null && $amountValue !== '') {
        if (is_numeric($amountValue)) {
            $amount = (float)$amountValue;
        }
    }

    $pointOfInitiation = getTag($items, '01');

    $isDynamic = ($pointOfInitiation === '12');

    /*
     * Validasi CRC.
     */
    $crcValid = verifyCrc($payload);

    return [
        'valid' => $crcValid,
        'merchant_name' => $merchantName,
        'city_name' => $cityName,
        'postal_code' => $postalCode,
        'nmid' => findNmid($items),
        'is_dynamic' => $isDynamic,
        'amount' => $amount
    ];
}


/*
|--------------------------------------------------------------------------
| MODIFY QRIS
|--------------------------------------------------------------------------
*/

function modifyQris(
    string $payload,
    string $merchantName,
    string $cityName,
    string $postalCode,
    float $amount,
    string $feeType,
    float $feeValue
): string {

    /*
     * Buang CRC lama terlebih dahulu.
     */
    $payloadWithoutCrc = removeCrc($payload);

    $items = parseTlv($payloadWithoutCrc);

    $result = [];

    foreach ($items as $item) {

        $id = $item['id'];

        /*
         * Merchant Name
         */
        if ($id === '59') {
            if ($merchantName === '') {
                continue;
            }

            $item['value'] = $merchantName;
        }

        /*
         * City
         */
        elseif ($id === '60') {
            if ($cityName === '') {
                continue;
            }

            $item['value'] = $cityName;
        }

        /*
         * Postal Code
         */
        elseif ($id === '61') {
            if ($postalCode === '') {
                continue;
            }

            $item['value'] = $postalCode;
        }

        /*
         * Amount
         *
         * Kalau amount = 0,
         * tag 54 dihapus.
         */
        elseif ($id === '54') {
            if ($amount <= 0) {
                continue;
            }

            $item['value'] = formatAmount($amount);
        }

        /*
         * Fee Information.
         *
         * Kita buang fee lama.
         */
        elseif (
            $id === '55' ||
            $id === '56' ||
            $id === '57'
        ) {
            continue;
        }

        /*
         * CRC lama
         */
        elseif ($id === '63') {
            continue;
        }

        $result[] = $item;
    }

    /*
     * Jika amount baru diberikan tetapi QRIS
     * sebelumnya tidak mempunyai tag 54,
     * tambahkan tag 54 sebelum CRC.
     */
    if ($amount > 0 && getTag($result, '54') === null) {

        $newItems = [];

        foreach ($result as $item) {

            if ($item['id'] === '58') {
                $newItems[] = [
                    'id' => '54',
                    'value' => formatAmount($amount)
                ];
            }

            $newItems[] = $item;
        }

        $result = $newItems;
    }

    /*
     * Fee QRIS.
     *
     * 55 = fee type
     * 56 = fixed fee
     * 57 = percentage fee
     */
    if ($amount > 0 && $feeType !== 'none') {

        if ($feeType === 'fixed') {

            $result[] = [
                'id' => '55',
                'value' => '02'
            ];

            $result[] = [
                'id' => '56',
                'value' => formatAmount($feeValue)
            ];
        }

        elseif ($feeType === 'percent') {

            $result[] = [
                'id' => '55',
                'value' => '01'
            ];

            $result[] = [
                'id' => '57',
                'value' => formatDecimal($feeValue)
            ];
        }
    }

    /*
     * Susun kembali payload.
     */
    $newPayload = '';

    foreach ($result as $item) {

        $value = (string)$item['value'];

        $newPayload .=
            $item['id'] .
            str_pad(
                (string)strlen($value),
                2,
                '0',
                STR_PAD_LEFT
            ) .
            $value;
    }

    /*
     * Tambahkan CRC.
     */
    $crc = crc16ccitt($newPayload . '6304');

    return $newPayload . '6304' . $crc;
}


/*
|--------------------------------------------------------------------------
| AMOUNT FORMAT
|--------------------------------------------------------------------------
*/

function formatAmount(float $amount): string
{
    return number_format(
        round($amount),
        0,
        '',
        ''
    );
}

function formatDecimal(float $value): string
{
    $formatted = rtrim(
        rtrim(
            number_format($value, 2, '.', ''),
            '0'
        ),
        '.'
    );

    return $formatted;
}


/*
|--------------------------------------------------------------------------
| REMOVE CRC
|--------------------------------------------------------------------------
*/

function removeCrc(string $payload): string
{
    if (strlen($payload) >= 8) {

        $last8 = substr($payload, -8);

        if (substr($last8, 0, 4) === '6304') {
            return substr($payload, 0, -8);
        }
    }

    return $payload;
}


/*
|--------------------------------------------------------------------------
| CRC16-CCITT
|--------------------------------------------------------------------------
*/

function crc16ccitt(string $data): string
{
    $crc = 0xFFFF;

    $length = strlen($data);

    for ($i = 0; $i < $length; $i++) {

        $crc ^= ord($data[$i]) << 8;

        for ($j = 0; $j < 8; $j++) {

            if (($crc & 0x8000) !== 0) {
                $crc = (($crc << 1) ^ 0x1021) & 0xFFFF;
            } else {
                $crc = ($crc << 1) & 0xFFFF;
            }
        }
    }

    return strtoupper(str_pad(
        dechex($crc),
        4,
        '0',
        STR_PAD_LEFT
    ));
}


/*
|--------------------------------------------------------------------------
| VERIFY CRC
|--------------------------------------------------------------------------
*/

function verifyCrc(string $payload): bool
{
    if (strlen($payload) < 8) {
        return false;
    }

    $crcPosition = strrpos($payload, '6304');

    if ($crcPosition === false) {
        return false;
    }

    $provided = substr($payload, $crcPosition + 4, 4);

    if (strlen($provided) !== 4) {
        return false;
    }

    $data = substr($payload, 0, $crcPosition + 4);

    $calculated = crc16ccitt($data);

    return strtoupper($provided) === strtoupper($calculated);
}
