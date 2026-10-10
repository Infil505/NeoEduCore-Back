<?php

namespace App\Support;

use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Paginador con solo lo que usa quien consume la API.
 *
 * El de Laravel añade `links` (una entrada con su URL por CADA página),
 * `first_page_url`, `last_page_url`, `next_page_url`, `prev_page_url` y `path`:
 * es un listado de enlaces pensado para vistas Blade. Un cliente
 * que pide `?page=N` no los necesita, y en un colegio con poca conexión cada
 * byte de cada listado cuenta. Se conserva todo lo demás con el mismo nombre.
 */
class PaginadorLigero extends LengthAwarePaginator
{
    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'current_page' => $this->currentPage(),
            'data'         => $this->items->toArray(),
            'from'         => $this->firstItem(),
            'last_page'    => $this->lastPage(),
            'per_page'     => $this->perPage(),
            'to'           => $this->lastItem(),
            'total'        => $this->total(),
        ];
    }
}
