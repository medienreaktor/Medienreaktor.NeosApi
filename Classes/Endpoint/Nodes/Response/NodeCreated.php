<?php
declare(strict_types=1);

namespace Neos\Api\Endpoint\Nodes\Response;

use Neos\Api\Endpoint\Nodes\Schema\Node;
use Neos\Api\Shared\Response\Created;
use Neos\OpenApi\Binding\TypeReference;
use Neos\OpenApi\Response\ApiResponseWithHeaders;

/**
 * A 201 with the new node (or variant, or copy) and where to find it
 */
final readonly class NodeCreated implements ApiResponseWithHeaders
{
    use Created;

    /**
     * @param string $path the node's path relative to the request URI, which differs per operation
     */
    private function __construct(
        private Node $node,
        private string $path,
    ) {
    }

    /**
     * For POST …/cr/{contentRepositoryId}/nodes, relative to …/cr/{contentRepositoryId}/
     */
    public static function node(Node $node): self
    {
        return new self($node, 'nodes/' . rawurlencode($node->nodeAggregateId->value));
    }

    /**
     * For POST …/nodes/{nodeAggregateId}/variants, relative to …/nodes/{nodeAggregateId}/
     */
    public static function variant(Node $node): self
    {
        return new self($node, '../' . rawurlencode($node->nodeAggregateId->value));
    }

    /**
     * For POST …/nodes/{nodeAggregateId}/copy, relative to …/nodes/{nodeAggregateId}/
     */
    public static function copy(Node $node): self
    {
        return new self($node, '../' . rawurlencode($node->nodeAggregateId->value));
    }

    public static function description(): string
    {
        return 'The node was created';
    }

    public static function locationDescription(): string
    {
        return 'The URI of the new node in its workspace and dimension space point, relative to the request URI';
    }

    public static function bodyType(): TypeReference
    {
        return TypeReference::of(Node::class);
    }

    public function body(): Node
    {
        return $this->node;
    }

    public function headers(): array
    {
        return ['Location' => sprintf(
            '%s?%s',
            $this->path,
            http_build_query(['workspaceName' => $this->node->workspaceName->value, 'dimensionSpacePoint' => $this->node->dimensionSpacePoint->value], '', '&', PHP_QUERY_RFC3986),
        )];
    }
}
