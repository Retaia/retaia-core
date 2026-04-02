<?php

namespace App\Tests\Unit\Application\Job;

use App\Application\Job\SubmitJobResultValidator;
use PHPUnit\Framework\TestCase;

final class SubmitJobResultValidatorTest extends TestCase
{
    private SubmitJobResultValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SubmitJobResultValidator();
    }

    public function testAllowsFactsPatchForExtractFacts(): void
    {
        self::assertTrue($this->validator->isAllowedForJobType('extract_facts', [
            'facts_patch' => ['duration_ms' => 123],
            'warnings' => ['minor'],
            'metrics' => ['elapsed_ms' => 10],
        ]));
    }

    public function testRejectsFactsPatchWithUnknownField(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('extract_facts', [
            'facts_patch' => [
                'duration_ms' => 123,
                'unknown_field' => 42,
            ],
        ]));
    }

    public function testRejectsTranscriptPatchOutsideTranscribeAudio(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('extract_facts', [
            'transcript_patch' => ['status' => 'DONE'],
        ]));
    }

    public function testAllowsTranscriptPatchForTranscribeAudio(): void
    {
        self::assertTrue($this->validator->isAllowedForJobType('transcribe_audio', [
            'transcript_patch' => [
                'status' => 'DONE',
                'text' => 'hello',
                'text_preview' => 'hello',
                'language' => 'en',
                'updated_at' => '2026-04-02T10:00:00+00:00',
            ],
        ]));
    }

    public function testRejectsTranscriptPatchWithInvalidStatus(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('transcribe_audio', [
            'transcript_patch' => [
                'status' => 'INVALID',
            ],
        ]));
    }

    public function testRejectsEncodedDerivedPayloadWithUnknownKind(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    ['kind' => 'bad-kind', 'ref' => 'preview.mp4'],
                ],
            ],
        ]));
    }

    public function testRejectsDerivedPayloadWithEmptyOrNonStringRef(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    ['kind' => 'video', 'ref' => ''],
                ],
            ],
        ]));

        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    ['kind' => 'video', 'ref' => null],
                ],
            ],
        ]));
    }

    public function testRejectsDerivedPayloadWithNonIntegerSizeBytes(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    [
                        'kind' => 'video',
                        'ref' => 'preview.mp4',
                        'size_bytes' => '100',
                    ],
                ],
            ],
        ]));

        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    [
                        'kind' => 'video',
                        'ref' => 'preview.mp4',
                        'size_bytes' => 1.23,
                    ],
                ],
            ],
        ]));
    }

    public function testRejectsDerivedPayloadWithNonStringSha256(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => [
                'derived_manifest' => [
                    [
                        'kind' => 'video',
                        'ref' => 'preview.mp4',
                        'size_bytes' => 100,
                        'sha256' => 12345,
                    ],
                ],
            ],
        ]));
    }

    public function testRejectsInvalidWarningsShape(): void
    {
        self::assertFalse($this->validator->isAllowedForJobType('generate_preview', [
            'derived_patch' => ['derived_manifest' => []],
            'warnings' => ['ok', 42],
        ]));
    }
}
