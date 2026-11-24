/**
 * MN3: one small "AI" marker and one gentle tip per page. The marker says what the page's AI does for you, an
 * example to say, and how to get the most from it; `lesson` points at the lesson in the menu's help panel that
 * shows it in a 60-second video. Pages are matched by their address (the tab you are on).
 */

export type PageAi = {
    name: string;
    does: string;
    example: string;
    most: string;
    lesson?: string;
};

export type PageHint = { ai?: PageAi; tip?: string };

const LEAD_IQ = 'Lead-IQ';
const DEAL_IQ = 'Deal-IQ';
const ASSISTANT = 'Your AI Assistant';

export const PAGE_HINTS: Record<string, PageHint> = {
    // Dashboard
    '/dashboard': {
        ai: {
            name: ASSISTANT,
            does: 'Tells you what needs you today across leads, deals, agents and money.',
            example: 'What should I focus on today?',
            most: 'Ask it first thing each morning, then work down the list it gives you.',
            lesson: 'today',
        },
        tip: 'Two minutes here each morning is enough to know nothing is slipping.',
    },
    '/agent': {
        ai: {
            name: ASSISTANT,
            does: 'Tells you who to call and what is due on your deals today.',
            example: 'Who should I call first today?',
            most: 'Ask it each morning before you open email.',
            lesson: 'today',
        },
        tip: 'Your numbers are at the bottom of this page: My numbers, Reports and Business planner.',
    },
    '/today': {
        ai: {
            name: LEAD_IQ,
            does: 'Picks the ten people most likely to do business with you today and writes an opener for each.',
            example: 'What should I say to the first person on my list?',
            most: "Mark each person Done or Not today so tomorrow's list stays fresh.",
            lesson: 'today',
        },
        tip: 'Ten good calls a day is about 200 a month.',
    },
    '/engage': {
        ai: {
            name: ASSISTANT,
            does: 'Turns your yearly goal into weekly calls and appointments.',
            example: 'Am I on track for my goal this month?',
            most: 'Check the green or red bar each Monday.',
            lesson: 'game-plan',
        },
    },
    '/tasks': {
        ai: {
            name: ASSISTANT,
            does: 'Adds tasks for you when you say them out loud.',
            example: 'Remind me to call the Garcias on Friday at 10.',
            most: 'Give every task a date so it reminds you.',
            lesson: 'tasks',
        },
    },

    // CRM
    '/leads': {
        ai: {
            name: LEAD_IQ,
            does: 'Answers every new lead within a minute by text or call, asks what they need and books a time with you. Only people who gave consent, 8am to 9pm.',
            example: 'Which new leads have not been answered yet?',
            most: 'Read the Brief before you call: it says what they looked at and what they want.',
            lesson: 'new-leads',
        },
        tip: 'Leads answered within 5 minutes are far more likely to become clients.',
    },
    '/contacts': {
        ai: {
            name: ASSISTANT,
            does: 'Finds people in your database in plain words.',
            example: 'Show me past clients who bought more than 5 years ago.',
            most: 'Ask for a list, then add them all to a campaign in one step.',
            lesson: 'contacts',
        },
        tip: 'Past clients and your sphere bring most repeat and referral business. Keep them all here.',
    },
    '/inbox': {
        ai: {
            name: ASSISTANT,
            does: 'Drafts replies in your voice for you to check and send.',
            example: "Draft a reply to Maria about Saturday's showing.",
            most: 'Always read a draft before you send it.',
            lesson: 'inbox',
        },
        tip: 'Answer the oldest unread message first.',
    },
    '/appointments': {
        tip: 'Set your hours under Settings › My account › My availability so clients only book times that suit you.',
    },
    '/outreach': {
        ai: {
            name: ASSISTANT,
            does: 'Writes a whole follow-up campaign from a short description.',
            example:
                'Write a 6-email campaign for people thinking of selling next spring.',
            most: 'Start from a ready-made campaign and change the words to sound like you.',
            lesson: 'campaigns',
        },
        tip: 'Most buyers buy 6 to 18 months after they first ask. Campaigns keep you in touch the whole time.',
    },
    '/movewise': {
        tip: 'Accept a Movewise lead within the time shown, or it goes to the next agent.',
    },

    // Listings
    '/listings': {
        ai: {
            name: ASSISTANT,
            does: 'Searches the MLS in plain words.',
            example: 'Find 3-bed homes under $450k in the Heights with a yard.',
            most: 'Save a search to a client so they get new matches by email.',
            lesson: 'search',
        },
    },
    '/pricing': {
        ai: {
            name: 'List-IQ',
            does: 'Picks the closest comparables, adjusts for size and condition, and suggests a price range with the reasons.',
            example: 'What should 412 Oak St list for?',
            most: 'Untick any comparable that is not a fair match before you share the report.',
            lesson: 'pricing',
        },
        tip: 'Show the seller the Buyer demand page too.',
    },
    '/deals/listings': {
        ai: {
            name: 'List-IQ',
            does: 'Writes the listing description, photo captions and social posts.',
            example: 'Write a listing description for 412 Oak St.',
            most: 'Give it two or three things you love about the home.',
            lesson: 'my-listings',
        },
    },
    '/list-iq': {
        ai: {
            name: 'List-IQ',
            does: 'Writes listing descriptions, captions and posts from the listing details.',
            example: 'Write a listing description for 412 Oak St.',
            most: 'Tell it who the likely buyer is (a young family, a downsizer).',
            lesson: 'my-listings',
        },
    },

    // Transactions
    '/deals': {
        ai: {
            name: DEAL_IQ,
            does: 'Reads the signed contract, finds every party and date, and builds the deal with its checklist and reminders.',
            example:
                'Buyer deal, the Garcias, 412 Oak St Austin TX, $450k, closing Dec 18.',
            most: 'Check the dates it found once before you press Set it up.',
            lesson: 'start',
        },
        tip: 'Look at "At risk today" each morning and clear anything red.',
    },
    '/deals/risk': {
        ai: {
            name: DEAL_IQ,
            does: 'Watches every date and document and warns you before something is late.',
            example: 'What is due on my deals this week?',
            most: 'Turn on text reminders for deadlines (8am to 9pm only).',
            lesson: 'risk',
        },
    },
    '/deals/compliance': {
        ai: {
            name: 'DocIQ',
            does: 'Reads each document and checks signatures, initials and dates.',
            example: 'What is missing from the Garcia file?',
            most: 'Drop documents in as they are signed, not all at the end.',
            lesson: 'documents',
        },
    },
    '/deals/review': {
        tip: 'Files waiting longest are at the top. Approve or send back with a note; the agent is told straight away.',
    },
    '/forms/templates': {
        tip: 'Signed copies are saved to the deal by themselves.',
    },
    '/deals/money': {
        tip: 'This is the company dollar you can expect in the next 30, 60 and 90 days.',
    },

    // Marketing
    '/studio': {
        ai: {
            name: ASSISTANT,
            does: 'Writes the words and picks the photos for posts, flyers and videos.',
            example: 'Make a "Just listed" post for 412 Oak St.',
            most: 'Set your brand voice once so everything sounds like you.',
            lesson: 'studio',
        },
    },
    '/social': {
        tip: 'Posting a little every week beats posting a lot once a month.',
    },
    '/marketing-check': {
        tip: 'Check every ad before you publish it. Fair-housing mistakes are costly.',
    },

    // Website & IDX
    '/settings/website': {
        tip: 'Changes show in the preview. Nothing goes live until you press Publish.',
    },
    '/settings/website/blog': {
        ai: {
            name: 'Blog writer',
            does: 'Writes local articles in your voice, ready for Google.',
            example:
                'Write a guide to living in the Heights for first-time buyers.',
            most: 'One local article a week brings free visitors for years.',
            lesson: 'blog',
        },
    },
    '/settings/blog-writer': {
        ai: {
            name: 'Blog writer',
            does: 'Writes local articles in your voice, ready for Google.',
            example:
                'Write a guide to living in the Heights for first-time buyers.',
            most: 'One local article a week brings free visitors for years.',
            lesson: 'blog',
        },
    },
    '/settings/mls': {
        tip: 'Listings appear on your website once your board approves the connection.',
    },

    // Agents
    '/users': {
        tip: 'Give each new agent a commission plan when you invite them, so their first deal pays correctly.',
    },
    '/roster': {
        tip: 'You and the agent are both reminded before a licence expires.',
    },
    '/recruiting': {
        ai: {
            name: ASSISTANT,
            does: 'Finds agents worth talking to by their sales in your area.',
            example: 'Show me agents with 10+ sales last year in Austin.',
            most: 'Add them to a recruiting campaign so you follow up without thinking.',
            lesson: 'recruiting',
        },
    },
    '/learn': {
        ai: {
            name: 'Learn-IQ',
            does: 'Picks the next lesson from what you are working on, and lets you practise conversations.',
            example: 'Help me practise a listing appointment.',
            most: 'Five minutes a day is enough.',
            lesson: 'training',
        },
    },

    // Commissions
    '/compensation/plans': {
        tip: 'Try a plan in the Simulator before you give it to an agent.',
    },
    '/compensation/simulator': {
        tip: 'Type a few sale prices to see how a plan works below and above the cap.',
    },
    '/finance/payouts': {
        tip: 'Agents get a statement by email when you pay them.',
    },
    '/my-money': {
        ai: {
            name: ASSISTANT,
            does: 'Answers questions about your income.',
            example: 'How much will I earn from deals closing this month?',
            most: 'Ask it before you plan a big purchase.',
            lesson: 'my-money',
        },
    },
    '/my-plan': { tip: 'The bar shows how close you are to your cap.' },

    // Accounting
    '/finance/receipts': {
        tip: 'Agents are only paid once the money received matches the CDA, or a difference is accepted.',
    },
    '/finance/bank': {
        tip: 'Match the bank weekly. It takes minutes when you keep up.',
    },
    '/finance/periods': {
        tip: 'Close each month once the bank matches. Closed months cannot be changed by accident.',
    },

    // Reports
    '/insights': {
        ai: {
            name: ASSISTANT,
            does: 'Answers questions about your numbers.',
            example:
                'How many deals did we close last quarter, and from which sources?',
            most: 'Click any number to see the deals behind it.',
            lesson: 'overview',
        },
    },
    '/insights/me': {
        ai: {
            name: ASSISTANT,
            does: 'Answers questions about your own numbers.',
            example: 'How does this year compare with last year?',
            most: 'Check once a week.',
        },
    },

    // Settings
    '/settings/roles': {
        tip: 'Give each person only what they need. You can change it at any time.',
    },
    '/settings/billing': {
        tip: 'AI credits used this month are shown here and at the bottom of the menu.',
    },
    '/settings/fair-housing': {
        tip: 'Required legal text and disclaimers are read-only and cannot be removed.',
    },
};
