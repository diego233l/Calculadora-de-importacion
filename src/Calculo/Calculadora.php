<?php

declare(strict_types=1);

namespace CalculadoraImportacion\Calculo;

/**
 * Punto de entrada del motor. Función pura: mismas entradas y mismas tablas dan siempre el mismo resultado.
 *
 * Devuelve:
 *   partidas   líneas del coste (precio, impuestos, costes fijos, transporte); el total es su suma
 *   intermedios cálculos que no son un coste pero explican otros (valor venal)
 *   total      suma de las partidas calculables
 *   completo   false si alguna partida no se ha podido calcular (el total es entonces parcial)
 *   avisos     advertencias para mostrar al usuario
 *   derivados  datos deducidos (nuevo/usado, antigüedad, CVF, distancia, tipos aplicados...)
 */
final class Calculadora
{
    /** @throws EntradaInvalida */
    public static function calcular(array $entrada, array $tablas): array
    {
        $n = Entrada::normalizar($entrada, $tablas);
        $regimenCanarias = $tablas['ccaa'][$n['ccaa']]['regimen'] === 'canarias';
        $tipoIndirecto = (float) ($regimenCanarias ? $tablas['iva']['igic']['valor'] : $tablas['iva']['tipo_iva_es']);

        $clasif = ItpIva::clasificar($n, $tablas);
        $precioNeto = ItpIva::precioNeto($n, $tablas, $clasif['nuevo']);
        $vv = ValorVenal::calcular($n, $tablas);
        $cvf = CaballosFiscales::calcular($n, $tablas);

        $motivoNuevo = $clasif['por_meses'] && $clasif['por_km'] ? 'menos de 6 meses y menos de 6.000 km'
            : ($clasif['por_meses'] ? 'menos de 6 meses desde la primera matriculación' : 'no más de 6.000 km');

        $iedmt = Iedmt::calcular($n, $tablas, [
            'nuevo' => $clasif['nuevo'],
            'precio_neto' => $precioNeto,
            'valor_venal' => $vv['valor'],
            'tipo_indirecto' => $tipoIndirecto,
        ]);
        $itpIva = ItpIva::calcular($n, $tablas, [
            'nuevo' => $clasif['nuevo'],
            'precio_neto' => $precioNeto,
            'valor_venal' => $vv['valor'],
            'cvf' => $cvf['cvf'],
            'antiguedad_anios' => $vv['antiguedad_anios'],
            'tipo_indirecto' => $tipoIndirecto,
            'motivo_nuevo' => $motivoNuevo,
        ]);
        $ivtm = Ivtm::calcular($n, $tablas, $cvf['cvf'], $cvf['motivo']);
        $fijos = CostesFijos::calcular($n, $tablas);
        $transporte = Transporte::calcular($n, $tablas);

        $partidas = array_merge(
            [self::partidaPrecio($n, $precioNeto, $clasif['nuevo'])],
            $iedmt['partidas'],
            $itpIva['partidas'],
            $ivtm['partidas'],
            $fijos['partidas'],
            $transporte['partidas']
        );
        $avisos = array_merge($iedmt['avisos'], $itpIva['avisos'], $ivtm['avisos'], $transporte['avisos']);
        $avisos[] = Partida::aviso(
            'estimacion_orientativa',
            'Estimación orientativa; confirma con tu gestoría o Hacienda.',
            'info'
        );

        $total = 0.0;
        $completo = true;
        $pendiente = false;
        $estimaciones = false;
        foreach ($partidas as $p) {
            if ($p['estado'] === 'ok') {
                $total += $p['importe'];
            } else {
                $completo = false;
            }
            $pendiente = $pendiente || $p['verificar'];
            $estimaciones = $estimaciones || $p['estimacion'];
        }
        $total = Dinero::redondear($total);
        if (!$completo) {
            $avisos[] = Partida::aviso('total_parcial', 'Hay partidas que no se han podido calcular: el total mostrado es parcial.');
        }
        if ($pendiente) {
            $avisos[] = Partida::aviso('datos_sin_verificar', 'Alguna cifra se apoya en datos que aún están pendientes de verificar contra la norma oficial.', 'info');
        }

        return [
            'partidas' => $partidas,
            'intermedios' => [$vv['partida']],
            'total' => $total,
            'coste_sobre_precio' => Dinero::redondear($total - $partidas[0]['importe']),
            'precio_anuncio' => $n['precio'],
            'completo' => $completo,
            'verificar_pendiente' => $pendiente,
            'contiene_estimaciones' => $estimaciones,
            'avisos' => $avisos,
            'derivados' => [
                'nuevo' => $clasif['nuevo'],
                'nuevo_por_meses' => $clasif['por_meses'],
                'nuevo_por_km' => $clasif['por_km'],
                'ccaa' => $n['ccaa'],
                'antiguedad_anios' => $vv['antiguedad_anios'],
                'coeficiente_depreciacion' => $vv['coeficiente'],
                'valor_venal' => $vv['valor'],
                'precio_neto' => $precioNeto,
                'cvf' => $cvf['cvf'],
                'cvf_explicacion' => $cvf['explicacion'],
                'tipo_iedmt' => $iedmt['tipo'],
                'ivtm_anual' => $ivtm['cuota_anual'],
                'distancia' => $transporte['distancia'],
            ],
        ];
    }

    private static function partidaPrecio(array $n, float $precioNeto, bool $nuevo): array
    {
        $sinIva = $precioNeto !== $n['precio'];
        $explicacion = $sinIva
            ? sprintf('El anuncio pide %s con IVA alemán desglosado. Al ser un coche nuevo vendido a otro país de la UE, se factura sin ese IVA: pagas %s.',
                Partida::eur($n['precio']), Partida::eur($precioNeto))
            : 'Precio del anuncio tal cual.';
        return Partida::ok(
            'precio', $sinIva ? 'Precio del coche (sin IVA alemán)' : 'Precio del coche', $precioNeto, $n['precio'], null, null,
            'Anuncio de mobile.de', $explicacion, false
        );
    }
}
