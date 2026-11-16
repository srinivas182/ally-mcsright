/**
 * "Show me" tours (SM1): a few steps per page, pointing at the parts that matter, in English and Spanish. A step's
 * target is an element with data-tour="…"; steps whose element is not on the page are skipped.
 */
export type TourStep = { target: string; en: string; es: string };

export const TOURS: Record<string, TourStep[]> = {
    'simple/home': [
        {
            target: 'talk',
            en: 'Tap here and just say what you need. RightAlly shows you what it will do before it does anything.',
            es: 'Toque aquí y diga lo que necesita. RightAlly le muestra lo que hará antes de hacerlo.',
        },
        {
            target: 'buttons',
            en: 'The things agents do most, one tap each.',
            es: 'Lo que más hacen los agentes, con un solo toque.',
        },
        {
            target: 'today',
            en: 'What needs you today. Tap one to deal with it.',
            es: 'Lo que necesita hoy. Toque uno para atenderlo.',
        },
        {
            target: 'view',
            en: 'Switch to the full view or to Spanish here, any time.',
            es: 'Cambie aquí a la vista completa o a inglés cuando quiera.',
        },
    ],
    'deals/index': [
        {
            target: 'deal-start',
            en: 'Start a deal from a photo of the contract, or just describe it out loud.',
            es: 'Empiece una transacción con una foto del contrato, o descríbala en voz alta.',
        },
        {
            target: 'deal-list',
            en: 'All your deals. Tap one to see what it needs.',
            es: 'Todas sus transacciones. Toque una para ver lo que necesita.',
        },
    ],
    'deals/show': [
        {
            target: 'deal-iq',
            en: 'Tell Deal-IQ what to do: "move closing to Friday and tell the lender". You confirm before anything happens.',
            es: 'Dígale a Deal-IQ qué hacer: "mueve el cierre al viernes y avisa al prestamista". Usted confirma antes de que pase algo.',
        },
        {
            target: 'checklist',
            en: 'The documents this deal needs. Upload a file or take a photo for each.',
            es: 'Los documentos que necesita esta transacción. Suba un archivo o tome una foto de cada uno.',
        },
    ],
    'crm/today': [
        {
            target: 'today-list',
            en: 'The people most worth a call today, and why. Call, text or skip each one.',
            es: 'Las personas a quienes más conviene llamar hoy, y por qué. Llame, escriba o salte a cada una.',
        },
    ],
};
