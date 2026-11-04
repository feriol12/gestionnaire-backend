<?php
require dirname(__DIR__, 2).'/vendor/autoload.php';

// RFC 6238 Appendix B, public test material, never an application factor.
$vectors = [
    59 => ['94287082','46119246','90693936'],
    1111111109 => ['07081804','68084774','25091201'],
    1111111111 => ['14050471','67062674','99943326'],
    1234567890 => ['89005924','91819424','93441116'],
    2000000000 => ['69279037','90698825','38618901'],
    20000000000 => ['65353130','77737706','47863826'],
];
$seeds = ['12345678901234567890', '12345678901234567890123456789012', '1234567890123456789012345678901234567890123456789012345678901234'];
$count = 0;
foreach (['sha1','sha256','sha512'] as $i => $algo) {
    $totp = new PragmaRX\Google2FA\Google2FA;
    $totp->setEnforceGoogleAuthenticatorCompatibility(false);
    $totp->setAlgorithm($algo); $totp->setOneTimePasswordLength(8);
    $secret = ParagonIE\ConstantTime\Base32::encodeUpperUnpadded($seeds[$i]);
    foreach ($vectors as $time => $expected) {
        if ($totp->oathTotp($secret, intdiv($time,30)) !== $expected[$i]) throw new RuntimeException('RFC vector mismatch');
        $count++;
    }
}
echo json_encode(['standard'=>'RFC6238 Appendix B','passed'=>$count,'failed'=>0], JSON_PRETTY_PRINT)."\n";
