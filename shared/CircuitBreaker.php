<?php
/**
 * Simple file-backed circuit breaker for Payment Service protection.
 */
class CircuitBreaker
{
    private string $name;
    private int $failureThreshold;
    private int $recoveryTimeout;
    private string $stateFile;

    public function __construct(string $name = 'payment')
    {
        $config = require __DIR__ . '/../config/app.php';
        $cb = $config['circuit_breaker'];
        $this->name = $name;
        $this->failureThreshold = (int)$cb['failure_threshold'];
        $this->recoveryTimeout = (int)$cb['recovery_timeout'];
        $dir = sys_get_temp_dir() . '/oxford_rms_cb';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $this->stateFile = $dir . '/' . preg_replace('/[^a-z0-9_-]/i', '', $name) . '.json';
    }

    private function load(): array
    {
        if (!is_file($this->stateFile)) {
            return ['failures' => 0, 'opened_at' => null, 'state' => 'closed'];
        }
        $data = json_decode((string)file_get_contents($this->stateFile), true);
        return is_array($data) ? $data : ['failures' => 0, 'opened_at' => null, 'state' => 'closed'];
    }

    private function save(array $state): void
    {
        file_put_contents($this->stateFile, json_encode($state));
    }

    public function allowRequest(): bool
    {
        $state = $this->load();
        if (($state['state'] ?? 'closed') !== 'open') {
            return true;
        }
        $openedAt = (int)($state['opened_at'] ?? 0);
        if (time() - $openedAt >= $this->recoveryTimeout) {
            $state['state'] = 'half_open';
            $this->save($state);
            return true;
        }
        return false;
    }

    public function recordSuccess(): void
    {
        $this->save(['failures' => 0, 'opened_at' => null, 'state' => 'closed']);
    }

    public function recordFailure(): void
    {
        $state = $this->load();
        $failures = (int)($state['failures'] ?? 0) + 1;
        if ($failures >= $this->failureThreshold || ($state['state'] ?? '') === 'half_open') {
            $this->save(['failures' => $failures, 'opened_at' => time(), 'state' => 'open']);
            return;
        }
        $this->save(['failures' => $failures, 'opened_at' => null, 'state' => 'closed']);
    }

    public function status(): array
    {
        return $this->load();
    }
}
