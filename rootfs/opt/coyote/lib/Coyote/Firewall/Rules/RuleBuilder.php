<?php

namespace Coyote\Firewall\Rules;

/**
 * Fluent builder for firewall rules.
 *
 * Provides a chainable interface for constructing firewall rule definitions.
 */
class RuleBuilder
{
    /** @var array Rule definition being built */
    private array $rule = [];

    /**
     * Create a new rule builder.
     *
     * @return static
     */
    public static function create(): static
    {
        return new static();
    }

    /**
     * Set the chain for this rule.
     *
     * @param string $chain Chain name (INPUT, OUTPUT, FORWARD)
     * @return static
     */
    public function chain(string $chain): static
    {
        $this->rule['chain'] = strtoupper($chain);
        return $this;
    }

    /**
     * Set the protocol.
     *
     * @param string $protocol Protocol (tcp, udp, icmp, all)
     * @return static
     */
    public function protocol(string $protocol): static
    {
        $this->rule['protocol'] = strtolower($protocol);
        return $this;
    }

    /**
     * Set the source address or network.
     *
     * @param string $source Source IP or CIDR
     * @return static
     */
    public function source(string $source): static
    {
        $this->rule['source'] = $source;
        return $this;
    }

    /**
     * Set the destination address or network.
     *
     * @param string $destination Destination IP or CIDR
     * @return static
     */
    public function destination(string $destination): static
    {
        $this->rule['destination'] = $destination;
        return $this;
    }

    /**
     * Set the input interface.
     *
     * @param string $interface Interface name
     * @return static
     */
    public function inInterface(string $interface): static
    {
        $this->rule['interface'] = $interface;
        return $this;
    }

    /**
     * Set the output interface.
     *
     * @param string $interface Interface name
     * @return static
     */
    public function outInterface(string $interface): static
    {
        $this->rule['out_interface'] = $interface;
        return $this;
    }

    /**
     * Set the destination port.
     *
     * @param int|string $port Port number or range
     * @return static
     */
    public function port($port): static
    {
        $this->rule['port'] = (string)$port;
        return $this;
    }

    /**
     * Set the source port.
     *
     * @param int|string $port Port number or range
     * @return static
     */
    public function sourcePort($port): static
    {
        $this->rule['sport'] = (string)$port;
        return $this;
    }

    /**
     * Match connection state.
     *
     * @param string $state State(s) to match (e.g., "NEW", "ESTABLISHED,RELATED")
     * @return static
     */
    public function state(string $state): static
    {
        $this->rule['match'] = 'state';
        $this->rule['state'] = strtoupper($state);
        return $this;
    }

    /**
     * Set ICMP type for ICMP protocol rules.
     *
     * @param string $type ICMP type
     * @return static
     */
    public function icmpType(string $type): static
    {
        $this->rule['icmp_type'] = $type;
        return $this;
    }

    /**
     * Set the rule action to ACCEPT.
     *
     * @return static
     */
    public function accept(): static
    {
        $this->rule['action'] = 'ACCEPT';
        return $this;
    }

    /**
     * Set the rule action to DROP.
     *
     * @return static
     */
    public function drop(): static
    {
        $this->rule['action'] = 'DROP';
        return $this;
    }

    /**
     * Set the rule action to REJECT.
     *
     * @param string|null $rejectWith Reject type (e.g., "icmp-port-unreachable")
     * @return static
     */
    public function reject(?string $rejectWith = null): static
    {
        $this->rule['action'] = 'REJECT';
        if ($rejectWith !== null) {
            $this->rule['reject_with'] = $rejectWith;
        }
        return $this;
    }

    /**
     * Set the rule action to LOG.
     *
     * @param string $prefix Log prefix
     * @return static
     */
    public function log(string $prefix): static
    {
        $this->rule['log_prefix'] = $prefix;
        return $this;
    }

    /**
     * Set a custom action.
     *
     * @param string $action Action name
     * @return static
     */
    public function action(string $action): static
    {
        $this->rule['action'] = strtoupper($action);
        return $this;
    }

    /**
     * Add a comment to the rule.
     *
     * @param string $comment Rule comment
     * @return static
     */
    public function comment(string $comment): static
    {
        $this->rule['comment'] = $comment;
        return $this;
    }

    /**
     * Enable/disable the rule.
     *
     * @param bool $enabled Whether rule is enabled
     * @return static
     */
    public function enabled(bool $enabled = true): static
    {
        $this->rule['enabled'] = $enabled;
        return $this;
    }

    /**
     * Build and return the rule definition.
     *
     * @return array Rule definition
     */
    public function build(): array
    {
        // Set defaults
        if (!isset($this->rule['chain'])) {
            $this->rule['chain'] = 'INPUT';
        }
        if (!isset($this->rule['action'])) {
            $this->rule['action'] = 'DROP';
        }
        if (!isset($this->rule['enabled'])) {
            $this->rule['enabled'] = true;
        }

        return $this->rule;
    }

    /**
     * Reset the builder for creating another rule.
     *
     * @return static
     */
    public function reset(): static
    {
        $this->rule = [];
        return $this;
    }

    /**
     * Create a rule from an array definition.
     *
     * @param array $definition Rule definition
     * @return static
     */
    public static function fromArray(array $definition): static
    {
        $builder = new static();
        $builder->rule = $definition;
        return $builder;
    }
}
