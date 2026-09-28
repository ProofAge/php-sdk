<?php

declare(strict_types=1);

namespace ProofAge\Sdk\Http;

/**
 * What a request says about the software that sent it: `X-ProofAge-Sdk` and the default
 * `User-Agent`. Built once by Client from its config and applied by SignMiddleware to each
 * attempt, after the user middleware, so no middleware can send a request without the SDK's
 * own token. Neither header is covered by the HMAC signature.
 *
 * @internal configure it through Client's `sdk_tokens` and `user_agent_prefix` options
 */
final class SdkIdentity
{
    public const HEADER = 'X-ProofAge-Sdk';

    /**
     * @param  list<string>  $tokens  `name/version`, outermost wrapper first, this SDK's own last
     * @param  string  $userAgent  sent only when the request has no User-Agent of its own
     */
    public function __construct(
        public readonly array $tokens,
        public readonly string $userAgent,
    ) {}

    public function header(): string
    {
        return implode(' ', $this->tokens);
    }

    /**
     * X-ProofAge-Sdk is replaced whatever a middleware set (any spelling), so the wire value
     * is always the configured one. User-Agent is added only when absent: a caller's own wins.
     */
    public function apply(Request $request): Request
    {
        $request = $request->withHeader(self::HEADER, $this->header());

        if ($request->header('User-Agent') === null) {
            $request = $request->withHeader('User-Agent', $this->userAgent);
        }

        return $request;
    }
}
