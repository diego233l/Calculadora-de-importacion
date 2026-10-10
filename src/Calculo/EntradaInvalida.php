<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/** La entrada del cálculo no es válida; $errores lleva un mensaje en español por cada problema. */
final class EntradaInvalida extends \InvalidArgumentException
{
    /** @var string[] */
    public array $errores;

    /** @param string[] $errores */
    public function __construct(array $errores)
    {
        $this->errores = $errores;
        parent::__construct(implode(' ', $errores));
    }
}
