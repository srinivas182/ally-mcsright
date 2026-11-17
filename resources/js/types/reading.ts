/** AX1: each person's easier-reading choices. */
export type Reading = {
    text: 'standard' | 'large' | 'largest';
    contrast: boolean;
    motion: boolean;
    links: boolean;
};
