<?php

namespace App\Support\Http;

use Dedoc\Scramble\Contracts\DocumentTransformer;
use Dedoc\Scramble\OpenApiContext;
use Dedoc\Scramble\Support\Generator\Header;
use Dedoc\Scramble\Support\Generator\OpenApi;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Server;
use Dedoc\Scramble\Support\Generator\Types\ArrayType;
use Dedoc\Scramble\Support\Generator\Types\BooleanType;
use Dedoc\Scramble\Support\Generator\Types\ObjectType;
use Dedoc\Scramble\Support\Generator\Types\StringType;

final class ApiContract implements DocumentTransformer
{
    public function handle(OpenApi $document, OpenApiContext $context): void
    {
        $document->servers = [new Server('/'.$context->config->apiPath()->serverPath())];
        $meta = $document->components->addSchema('ApiMeta', Schema::fromType(
            (new ObjectType)->addProperty('request_id', new StringType)->setRequired(['request_id']),
        ));
        $error = $document->components->addSchema('ApiError', Schema::fromType((new ObjectType)
            ->addProperty('success', (new BooleanType)->const(false))
            ->addProperty('message', new StringType)
            ->addProperty('code', new StringType)
            ->addProperty('errors', (new ObjectType)->additionalProperties((new ArrayType)->setItems(new StringType)))
            ->addProperty('meta', $meta)->setRequired(['success', 'message', 'code', 'meta'])));

        foreach ($document->paths as $path) {
            foreach ($path->operations as $operation) {
                foreach ($operation->responses ?? [] as $response) {
                    $response = $response instanceof Reference && $response->referenceType === 'responses' ? $response->resolve() : $response;
                    if (! $response instanceof Response || ! isset($response->content['application/json'])) {
                        continue;
                    }
                    if ((int) $response->code === 429) {
                        $response->addHeader('Retry-After', new Header(description: 'Seconds to wait before retrying.', schema: Schema::fromType(new StringType)));
                    }
                    $schema = $response->getContent('application/json');
                    if (! $schema instanceof Schema || ! $schema->type instanceof ObjectType) {
                        continue;
                    }
                    $body = $schema->type;
                    // Laravel's inferred exception bodies omit the envelope added by ApiExceptionRenderer.
                    if ($response->code >= 400 && $body->hasProperty('message') && ! array_diff(array_keys($body->properties), ['message', 'errors'])) {
                        $response->setContent('application/json', $error);
                    } elseif ($body->hasProperty('success') && $body->hasProperty('meta')) {
                        $body->addProperty('success', (new BooleanType)->const($response->code < 400));
                        $metadata = $body->properties['meta'];
                        if ($metadata instanceof ObjectType) {
                            $metadata->addProperty('request_id', new StringType)->addRequired(['request_id']);
                        }
                    }
                }
            }
        }
    }
}
