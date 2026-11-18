/**
 * Words for the app shell (search, install, offline) in English and Spanish (PW1, specs/core/17). The menu itself
 * comes translated from the server.
 */
const words = {
    en: {
        searchButton: 'Search or jump to…',
        searchPlaceholder: 'Type a name, address or page',
        searchTitle: 'Search',
        searchHint: 'Find people, contacts, deals, listings and pages',
        pages: 'Pages',
        nothing: 'Nothing found.',
        searching: 'Searching…',
        typeMore: 'Type two or more letters to search your records.',
        install: 'Add to home screen',
        installTitle: 'Add RightAlly to your home screen',
        installIos:
            'On iPhone or iPad: tap the Share button (the square with an arrow), then "Add to Home Screen".',
        installOther:
            'In your browser menu, choose "Install app" or "Add to Home screen".',
        installDone: 'Done. Open RightAlly from your home screen.',
        close: 'Close',
    },
    es: {
        searchButton: 'Buscar o ir a…',
        searchPlaceholder: 'Escriba un nombre, dirección o página',
        searchTitle: 'Buscar',
        searchHint:
            'Busque personas, contactos, operaciones, propiedades y páginas',
        pages: 'Páginas',
        nothing: 'No se encontró nada.',
        searching: 'Buscando…',
        typeMore: 'Escriba dos o más letras para buscar en sus registros.',
        install: 'Agregar a la pantalla de inicio',
        installTitle: 'Agregue RightAlly a su pantalla de inicio',
        installIos:
            'En iPhone o iPad: toque Compartir (el cuadro con una flecha) y luego "Agregar a inicio".',
        installOther:
            'En el menú del navegador, elija "Instalar aplicación" o "Agregar a la pantalla de inicio".',
        installDone: 'Listo. Abra RightAlly desde su pantalla de inicio.',
        close: 'Cerrar',
    },
} as const;

export type ShellWords = (typeof words)['en'];

export function shellWords(locale: string | null | undefined): ShellWords {
    return locale === 'es' ? words.es : words.en;
}
