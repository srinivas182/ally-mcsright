<?php

declare(strict_types=1);

namespace Modules\Sites\Http\Controllers;

/** The printed demand report's words, in English and Spanish (specs/grow/10). */
final class DemandWords
{
    /** @return array<string, string> */
    public static function for(string $lang): array
    {
        return $lang === 'es' ? [
            'title' => 'Demanda de compradores', 'for' => 'Para', 'prepared' => 'Preparado por', 'on' => 'el',
            'buyers' => 'compradores buscan una casa como esta', 'buyers_note' => 'Compradores de nuestra base de datos y nuestro sitio web cuyas búsquedas guardadas o solicitudes coinciden con la zona, el precio y el tamaño.',
            'curve' => 'Demanda según el precio', 'price' => 'Precio', 'count' => 'Compradores', 'shoppers' => 'personas vieron casas similares en nuestro sitio web en los últimos 90 días',
            'favourited' => 'las guardaron como favoritas', 'competition' => 'Casas similares a la venta ahora', 'median' => 'Precio mediano de lista', 'none' => 'Ninguna en este momento',
            'privacy' => 'Solo cifras: este informe nunca muestra nombres ni datos de contacto de los compradores.', 'asof' => 'Datos al',
            'equal' => 'Igualdad de oportunidades de vivienda.', 'print' => 'Imprimir',
        ] : [
            'title' => 'Buyer demand', 'for' => 'For', 'prepared' => 'Prepared by', 'on' => 'on',
            'buyers' => 'buyers want a home like this', 'buyers_note' => 'Buyers in our database and on our website whose saved searches or requests match the area, price and size.',
            'curve' => 'Demand by price', 'price' => 'Price', 'count' => 'Buyers', 'shoppers' => 'people looked at similar homes on our website in the last 90 days',
            'favourited' => 'saved them as favourites', 'competition' => 'Similar homes for sale now', 'median' => 'Median asking price', 'none' => 'None right now',
            'privacy' => 'Counts only: this report never shows buyers\' names or contact details.', 'asof' => 'Data as of',
            'equal' => 'Equal Housing Opportunity.', 'print' => 'Print',
        ];
    }
}
