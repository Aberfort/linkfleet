<?php

namespace App\Support;

/**
 * Thin seam over the DNS lookup so domain verification can be tested
 * without touching the network - tests bind a fake in the container.
 */
class DnsTxtLookup
{
    public function __construct(private DohResolver $resolver) {}

    /**
     * Every TXT value published at $name, or an empty array when the name
     * doesn't resolve.
     *
     * @return array<int, string>
     */
    public function txtValues(string $name): array
    {
        return array_map($this->unquote(...), $this->resolver->lookup($name, 'TXT'));
    }

    /**
     * DoH hands TXT data back the way it appears in a zone file: quoted, and
     * split into 255-byte chunks for long values ("part one" "part two").
     */
    private function unquote(string $data): string
    {
        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $data, $chunks);

        return $chunks[1] === [] ? $data : stripcslashes(implode('', $chunks[1]));
    }
}
