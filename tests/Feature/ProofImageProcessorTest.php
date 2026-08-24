<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Services\ProofImageProcessor;
use Tests\TestCase;

/**
 * ProofImageProcessor is the pipeline that runs on every proof upload:
 * hash the pristine bytes (for duplicate detection later), then burn a
 * visible EMP/ACC/timestamp watermark into the stored file. These tests
 * cover the two guarantees the rest of the code relies on: identical
 * originals hash the same, and the returned hash is of the pre-watermark
 * bytes (otherwise duplicates would never match).
 */
class ProofImageProcessorTest extends TestCase
{
    private function makeImagePath(): string
    {
        $img = imagecreatetruecolor(400, 300);
        $green = imagecolorallocate($img, 0, 128, 0);
        imagefill($img, 0, 0, $green);
        $path = tempnam(sys_get_temp_dir(), 'proof').'.jpg';
        imagejpeg($img, $path, 90);
        imagedestroy($img);
        return $path;
    }

    public function test_returns_a_sha256_hex_hash_of_the_original_bytes(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $path = $this->makeImagePath();
        $expected = hash_file('sha256', $path);

        $hash = (new ProofImageProcessor())->process($path, $employee, $assignment);

        $this->assertSame($expected, $hash);
        $this->assertSame(64, strlen($hash));
        @unlink($path);
    }

    public function test_two_identical_originals_produce_the_same_hash_even_after_watermarking(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $pathA = $this->makeImagePath();
        // Byte-copy so the two inputs are identical originals — as if the
        // employee uploaded the same saved file to two different accounts.
        $pathB = tempnam(sys_get_temp_dir(), 'proof').'.jpg';
        copy($pathA, $pathB);

        $processor = new ProofImageProcessor();
        $hashA = $processor->process($pathA, $employee, $assignment);
        $hashB = $processor->process($pathB, $employee, $assignment);

        $this->assertSame($hashA, $hashB);

        // Sanity: watermarking DID change the stored files, otherwise this
        // test would trivially pass. Hash the disk contents now — they
        // should be different from each other (per-upload watermark text
        // renders slightly differently at different times), and different
        // from the hash we returned.
        $onDiskA = hash_file('sha256', $pathA);
        $this->assertNotSame($hashA, $onDiskA, 'watermark did not modify the file — process is a no-op');

        @unlink($pathA);
        @unlink($pathB);
    }

    public function test_a_missing_or_unreadable_file_raises_a_clear_error(): void
    {
        $tenant = $this->makeTenant();
        $office = $this->makeOffice($tenant);
        $employee = $this->makeUser($tenant, UserRole::Employee, $office);
        $account = $this->makeAccount($tenant);
        $assignment = $this->makeAssignment($tenant, $account, $employee);

        $this->expectException(\RuntimeException::class);

        (new ProofImageProcessor())->process('/no/such/file.jpg', $employee, $assignment);
    }
}
