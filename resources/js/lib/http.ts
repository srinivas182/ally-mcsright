/** JSON and form posts to our own endpoints, with Laravel's CSRF cookie. */
function xsrf(): string {
    const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

async function handle<T>(response: Response): Promise<T> {
    const body = (await response.json().catch(() => ({}))) as T & {
        message?: string;
    };
    if (!response.ok) {
        throw new Error(
            body.message ?? 'Something went wrong. Please try again.',
        );
    }
    return body;
}

export async function postJson<T>(url: string, data: unknown): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-XSRF-TOKEN': xsrf(),
        },
        body: JSON.stringify(data),
    });
    return handle<T>(response);
}

export async function postForm<T>(url: string, form: FormData): Promise<T> {
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
        body: form,
    });
    return handle<T>(response);
}
