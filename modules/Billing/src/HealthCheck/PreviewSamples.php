<?php

declare(strict_types=1);

namespace Modules\Billing\HealthCheck;

/** Made-up items for the branded preview: no real people, clearly labelled as samples. */
final class PreviewSamples
{
    /**
     * @return array{label: string, agent: string, items: list<string>, note: string, switch: string, back: string}
     */
    public static function for(string $lang): array
    {
        return $lang === 'es' ? [
            'label' => 'Datos de ejemplo', 'agent' => 'Pat',
            'items' => ['Llamar a un cliente anterior: hoy cumple un año en su casa', 'Calle Roble 12: la inspección termina el viernes', 'Firmar el acuerdo de venta de Av. Pino 48'],
            'note' => 'Así verían RightAlly sus agentes en la vista sencilla, con sus colores.', 'switch' => 'View in English', 'back' => 'Volver al informe',
        ] : [
            'label' => 'Sample data', 'agent' => 'Pat',
            'items' => ['Call a past client: one year in their home today', '12 Oak St: the inspection period ends Friday', 'Sign the listing agreement for 48 Pine Ave'],
            'note' => 'This is how your agents would see RightAlly in simple view, in your colours.', 'switch' => 'Ver en español', 'back' => 'Back to the report',
        ];
    }
}
