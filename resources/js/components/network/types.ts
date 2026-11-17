export type NetworkCard = {
    ulid: string;
    code: string;
    name: string;
    brokerage: string;
    state: string;
    areas: string[];
    sides: string;
    languages: string[];
    bio: string | null;
    phone: string | null;
    verified: boolean;
    public: boolean;
    trust: {
        score: number | null;
        count: number;
        parts: Record<string, { score: number; label: string; detail: string }>;
    };
};

export type NetworkReferral = {
    ulid: string;
    status: string;
    status_label: string;
    side: 'buyer' | 'seller';
    area: string;
    price_band: string | null;
    timing: string | null;
    language: string | null;
    needs: string | null;
    fee_percent: string;
    other: { name: string; brokerage: string; ulid: string } | null;
    client: {
        first_name: string;
        last_name?: string | null;
        email?: string | null;
        phone?: string | null;
    } | null;
    agreement: string | null;
    reason: string | null;
    deal: string | null;
    fee_due_cents: number | null;
    closed_on: string | null;
    fee_sent: boolean;
    fee_paid: boolean;
    answer_by: string | null;
    sent_at: string;
    rating: number | null;
    disputed: boolean;
    dispute_note: string | null;
    dispute_reply: string | null;
    can_dispute: boolean;
};
