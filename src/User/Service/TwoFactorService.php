<?php

namespace App\User\Service;

use App\User\UserTwoFactorState;
use App\User\UserTwoFactorStateRepositoryInterface;

final class TwoFactorService
{
    public function __construct(
        private UserTwoFactorStateRepositoryInterface $repository,
        private TwoFactorSecretCipher $secretCipher,
        ?TwoFactorTotpService $totpService = null,
        ?TwoFactorRecoveryCodeService $recoveryCodeService = null,
    ) {
        $this->totpService = $totpService ?? new TwoFactorTotpService($secretCipher);
        $this->recoveryCodeService = $recoveryCodeService ?? new TwoFactorRecoveryCodeService();
    }

    private TwoFactorTotpService $totpService;

    private TwoFactorRecoveryCodeService $recoveryCodeService;

    public function isEnabled(string $userId): bool
    {
        $state = $this->state($userId);

        return (bool) ($state['enabled'] ?? false);
    }

    public function isPendingSetup(string $userId): bool
    {
        return $this->totpService->hasPendingSetup($this->state($userId));
    }

    /**
     * @return array{method: string, issuer: string, account_name: string, secret: string, otpauth_uri: string}
     */
    public function setup(string $userId, string $email): array
    {
        $state = $this->state($userId);
        $setup = $this->totpService->beginSetup($state, $email);
        $this->saveState($userId, $state);

        return $setup;
    }

    public function enable(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        if (!$this->totpService->enable($state, $otpCode)) {
            return false;
        }
        $this->saveState($userId, $state);

        return true;
    }

    public function disable(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        if (!$this->totpService->disable($state, $otpCode)) {
            return false;
        }
        $this->saveState($userId, $state);

        return true;
    }

    public function verifyLoginOtp(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        $before = $state;
        $isValid = $this->totpService->verifyLoginOtp($state, $otpCode);

        if ($state !== $before) {
            $this->saveState($userId, $state);
        }

        return $isValid;
    }

    public function verifyOtp(string $userId, string $otpCode): bool
    {
        $state = $this->state($userId);
        $before = $state;
        $isValid = $this->totpService->verifyEnabledOtp($state, $otpCode);

        if ($state !== $before) {
            $this->saveState($userId, $state);
        }

        return $isValid;
    }

    public function consumeRecoveryCode(string $userId, string $recoveryCode): bool
    {
        $state = $this->state($userId);
        if (!$this->recoveryCodeService->consumeRecoveryCode($state, $recoveryCode)) {
            return false;
        }
        $this->saveState($userId, $state);

        return true;
    }

    /**
     * @return list<string>
     */
    public function regenerateRecoveryCodes(string $userId): array
    {
        $state = $this->state($userId);
        $codes = $this->recoveryCodeService->regenerateRecoveryCodes($state);
        $this->saveState($userId, $state);

        return $codes;
    }

    /**
     * @return array<string, mixed>
     */
    private function state(string $userId): array
    {
        return $this->stateModel($userId)->toStateArray();
    }

    /**
     * @param array<string, mixed> $state
     */
    private function saveState(string $userId, array $state): void
    {
        $existing = $this->repository->findByUserId($userId);
        $this->repository->save(UserTwoFactorState::fromStateArray($userId, $state, $existing));
    }

    private function stateModel(string $userId): UserTwoFactorState
    {
        return $this->repository->findByUserId($userId) ?? UserTwoFactorState::empty($userId);
    }
}
