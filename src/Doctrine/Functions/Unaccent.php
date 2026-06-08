<?php

declare(strict_types=1);

namespace App\Doctrine\Functions;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/**
 * Maps the PostgreSQL `unaccent(text)` function (provided by the `unaccent`
 * extension) into DQL so name searches can be made accent-insensitive:
 *
 *   UNACCENT(LOWER(a.name)) LIKE UNACCENT(LOWER(:term))
 *
 * Applying it to both sides means "Cité" matches a stored "Cite" and vice
 * versa, regardless of which side carries the accents.
 */
final class Unaccent extends FunctionNode
{
    private ?Node $string = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->string = $parser->StringPrimary();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return 'unaccent(' . $this->string->dispatch($sqlWalker) . ')';
    }
}
