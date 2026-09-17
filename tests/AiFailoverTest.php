<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * aiBuildChain() (includes/ai.php) — the order providers are tried in when one
 * is unavailable.
 *
 * Why this exists at all: the free tiers these reports run on answer 503 under
 * load. Without failover, a coordinator pressing the button after an exercise
 * simply cannot produce the report at the moment everyone is waiting for it.
 *
 * The ordering rules this pins:
 *   - the chosen provider goes first, always;
 *   - a provider with no key is not in the chain, because it cannot serve
 *     anything — including when it is the chosen one;
 *   - no provider appears twice, or a failure would be reported against it
 *     twice and the operator would think two things went wrong.
 */
final class AiFailoverTest extends TestCase
{
    private const ALL = ['deepseek', 'gemini'];

    public function testTheChosenProviderIsTriedFirst(): void
    {
        $this->assertSame(
            ['gemini', 'deepseek'],
            aiBuildChain('gemini', self::ALL, ['deepseek', 'gemini'])
        );
        $this->assertSame(
            ['deepseek', 'gemini'],
            aiBuildChain('deepseek', self::ALL, ['deepseek', 'gemini'])
        );
    }

    public function testProvidersWithoutAKeyAreNotTried(): void
    {
        $this->assertSame(['gemini'], aiBuildChain('gemini', self::ALL, ['gemini']));
    }

    /**
     * A chosen provider whose key was removed must not be attempted: it can
     * only fail, and it would delay the fallback that is going to serve the
     * request anyway.
     */
    public function testAChosenProviderWithNoKeyIsSkippedEntirely(): void
    {
        $this->assertSame(['deepseek'], aiBuildChain('gemini', self::ALL, ['deepseek']));
    }

    public function testNoProviderAppearsTwice(): void
    {
        $chain = aiBuildChain('gemini', self::ALL, ['gemini', 'deepseek']);
        $this->assertSame($chain, array_values(array_unique($chain)));
    }

    public function testNoKeysAnywhereMeansNoChain(): void
    {
        $this->assertSame([], aiBuildChain('gemini', self::ALL, []));
    }

    /**
     * The provider list is meant to grow — the settings page already derives
     * its fields from it. A third provider must join the chain behind the
     * primary without any change here.
     */
    public function testAThirdProviderJoinsTheChainAutomatically(): void
    {
        $all = ['deepseek', 'gemini', 'mistral'];
        $this->assertSame(
            ['mistral', 'deepseek', 'gemini'],
            aiBuildChain('mistral', $all, $all)
        );
        $this->assertSame(
            ['gemini', 'deepseek', 'mistral'],
            aiBuildChain('gemini', $all, $all)
        );
    }

    /**
     * An unknown primary (a stale ai_provider value after a provider is
     * withdrawn from the code) must not poison the chain — the remaining
     * configured providers still serve.
     */
    public function testAnUnknownPrimaryFallsThroughToTheConfiguredOnes(): void
    {
        $this->assertSame(
            ['deepseek', 'gemini'],
            aiBuildChain('retired-provider', self::ALL, ['deepseek', 'gemini'])
        );
    }

    /**
     * aiFailoverOrder() — free tiers before metered ones.
     *
     * This is the rule with a money consequence rather than a correctness one,
     * which is exactly why it needs a test: getting it wrong breaks nothing.
     * The reports still come out, they just come out of the provider that
     * bills for them, and nobody notices until the invoice.
     */
    public function testMeteredProvidersAreTriedLast(): void
    {
        $order = aiFailoverOrder();
        $this->assertSame('deepseek', end($order), 'the metered provider must be the last one tried');
        $this->assertSame(['gemini', 'grok'], array_slice($order, 0, 2));
    }

    /**
     * The whole point of the rank: with all three keys stored and the default
     * primary, a busy Gemini hands the work to Grok, and only a Grok failure
     * too reaches the provider that charges for it.
     */
    public function testTheDefaultChainSpendsNothingUntilBothFreeTiersFail(): void
    {
        $this->assertSame(
            ['gemini', 'grok', 'deepseek'],
            aiBuildChain('gemini', aiFailoverOrder(), ['deepseek', 'gemini', 'grok'])
        );
    }

    /**
     * Choosing the metered provider on purpose still puts it first. An admin
     * who picks it has decided to pay for the first attempt — a cost rule that
     * overrode an explicit choice would be a bug, not a saving.
     */
    public function testAnExplicitlyChosenMeteredProviderStillLeads(): void
    {
        $this->assertSame(
            ['deepseek', 'gemini', 'grok'],
            aiBuildChain('deepseek', aiFailoverOrder(), ['deepseek', 'gemini', 'grok'])
        );
    }

    /**
     * Every provider offered in Settings must declare where it is tried, or it
     * silently lands mid-chain on the default rank of 50 — between the free
     * tiers and the metered one, which is a position nobody chose.
     */
    public function testEveryProviderDeclaresAFailoverRank(): void
    {
        foreach (aiProviders() as $key => $meta) {
            $this->assertArrayHasKey('failover_rank', $meta, "provider '{$key}' has no failover_rank");
        }
    }
}
