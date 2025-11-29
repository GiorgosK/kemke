<?php

$user = 'api_user';
$pass = 'api_user';
$base = 'https://kemke.ddev.site/api/incoming';

$pdfPath = __DIR__ . '/13K.pdf';

$fileData = file_get_contents($pdfPath);
if ($fileData === false) {
    throw new RuntimeException("Unable to read $pdfPath. Place a 13K.pdf in the same folder.");
}
$totalSize = strlen($fileData);
if ($totalSize === 0) {
    throw new RuntimeException("$pdfPath is empty.");
}
$chunkSize = (int) ceil($totalSize / 3); // split into 3 chunks
$chunks = str_split($fileData, $chunkSize);
$expectedChunks = count($chunks);

function postJson($url, array $payload, $user, $pass) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => "$user:$pass",
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($payload),
    ]);
    $response = curl_exec($ch);
    if ($response === false) {
        throw new RuntimeException(curl_error($ch));
    }
    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, $response];
}

// 1) init
[$status, $resp] = postJson("$base/files/init", [
    'filename' => basename($pdfPath),
    'mime_type' => 'application/pdf',
    'expected_chunks' => $expectedChunks,
    'total_size' => $totalSize,
    'chunk_size' => $chunkSize,
], $user, $pass);
$init = json_decode($resp, true);
$uploadId = $init['upload_id'] ?? null;
if (!$uploadId) {
    throw new RuntimeException("Init failed ($status): $resp");
}
echo "Init OK, upload_id = $uploadId\n";

// 2) chunks (three chunks expected)
foreach ($chunks as $index => $chunk) {
    [$status, $resp] = postJson("$base/files/chunk", [
        'upload_id' => $uploadId,
        'chunk_index' => $index,
        'data' => base64_encode($chunk),
    ], $user, $pass);
    echo "Chunk $index -> $resp\n";
}

// 3) complete
[$status, $resp] = postJson("$base/files/complete", [
    'upload_id' => $uploadId,
], $user, $pass);
$complete = json_decode($resp, true);
$fid = $complete['fid'] ?? null;
if (!$fid) {
    throw new RuntimeException("Complete failed ($status): $resp");
}
echo "Complete OK, fid = $fid\n";

// 4) create case using fid
[$status, $resp] = postJson($base, [
    'subject' => 'Chunked upload demo (3 chunks)',
    'documents' => [
        ['files' => [
            ['fid' => $fid],
        ]],
    ],
], $user, $pass);
echo "Case response: $resp\n";
