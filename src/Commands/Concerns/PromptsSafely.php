<?php

namespace LaraSlice\Commands\Concerns;

use Symfony\Component\Console\Input\StreamableInputInterface;

/**
 * Prompts that can never hang a script (Stage C-6, finding
 * F1). A bare confirm() reads from stdin; when stdin is a
 * PIPE held open by a parent process (an AI runner, CI, a
 * background exec) the read blocks forever with zero
 * output — the Stage A cold port lost 22 minutes to
 * exactly this. The console's own isInteractive flag did
 * not save it, so promptability is decided here directly:
 * a prompt may only be asked when stdin is a real TTY and
 * the session is interactive. Otherwise the question's
 * DEFAULT is the answer — destructive questions default
 * to "no" and abort, progress questions default to "yes"
 * and the flags the caller passed govern the run.
 */
trait PromptsSafely
{
    protected function promptable(): bool
    {
        if ($this->input === null || ! $this->input->isInteractive()) {
            return false;
        }
        if (method_exists($this, 'option') && $this->option('no-interaction')) {
            return false;
        }
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        if (function_exists('posix_isatty')) {
            return @posix_isatty($stream ?? STDIN);
        }

        return true;
    }

    protected function safeConfirm(string $question, bool $default = false): bool
    {
        if (! $this->promptable()) {
            return $default;
        }

        return $this->confirm($question, $default);
    }
}
