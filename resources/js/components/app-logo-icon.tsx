import { useId } from 'react';
import type { SVGAttributes } from 'react';

/**
 * BR1: the RightAlly mark (resources/brand/rightally-logo.svg): the white "A" roof on the RightAlly blue tile.
 * Colours are the brand's own, so it reads the same on light and dark backgrounds.
 */
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    const id = useId().replace(/:/g, '');

    return (
        <svg
            viewBox="4.79 4.8 22.36 22.4"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
            {...props}
        >
            <defs>
                <linearGradient
                    id={`ra-mark-${id}`}
                    x1="15.97"
                    y1="4.8"
                    x2="15.97"
                    y2="27.2"
                    gradientUnits="userSpaceOnUse"
                >
                    <stop stopColor="#155DFC" />
                    <stop offset="1" stopColor="#06369E" />
                </linearGradient>
            </defs>
            <path
                d="M4.79102 9.60005C4.79102 6.94908 6.93604 4.80005 9.58206 4.80005H22.3582C25.0042 4.80005 27.1492 6.94908 27.1492 9.60005V22.4C27.1492 25.051 25.0042 27.2 22.3582 27.2H9.58206C6.93604 27.2 4.79102 25.051 4.79102 22.4V9.60005Z"
                fill={`url(#ra-mark-${id})`}
            />
            <path
                fillRule="evenodd"
                clipRule="evenodd"
                d="M15.2956 9.99394C15.5954 9.46882 16.3447 9.46882 16.6444 9.99394L23.0511 21.2186C23.3508 21.7437 22.9762 22.4001 22.3767 22.4001H19.1091C18.8421 22.4001 18.5937 22.2618 18.451 22.0336L15.97 18.8389L13.4891 22.0336C13.3463 22.2618 13.098 22.4001 12.831 22.4001H9.56334C8.96388 22.4001 8.58921 21.7437 8.88893 21.2186L15.2956 9.99394ZM13.4964 18.3621C13.387 18.5369 13.5112 18.765 13.7157 18.765H18.2242C18.4287 18.765 18.5529 18.5369 18.4436 18.3621L15.97 14.4081L13.4964 18.3621Z"
                fill="white"
            />
        </svg>
    );
}
