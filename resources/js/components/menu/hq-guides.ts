/**
 * HQ4: the HQ console's own menu guides, page tips and AI markers, in the same shape as the brokerage ones (MN3).
 * Guides open from the button on the right of the tabs (and by themselves the first time); a lesson's 60-second
 * video is added under Support › Help videos with the address guide:hq-<menu>:<lesson>, for example
 * guide:hq-customers:go-live. HQ staff cannot use Your AI Assistant, so the AI markers here only explain what the
 * AI on the page does and how to check it.
 */
import type { Guide } from '@/components/menu/guides';
import type { PageHint } from '@/components/menu/page-hints';

export const HQ_GUIDES: Record<string, Guide> = {
    Today: {
        title: 'Start each HQ day here',
        intro: 'To do lists everything waiting for HQ, with what to do and a button to the page where you do it. Numbers shows how RightAlly is doing across every brokerage, in totals only.',
        start: [
            'Open To do first thing each morning.',
            'Work from the top: each line says what to do. Press Open to go straight there.',
            'Check Numbers once a week for money, growth and AI use.',
        ],
        lessons: [
            {
                id: 'todo',
                title: 'Your To do list',
                why: 'Nothing waits for HQ without someone seeing it.',
                steps: [
                    'The count next to Today in the menu is how many things are waiting.',
                    'Each line says what is waiting and what to do, for example "Answer them".',
                    'Press Open, deal with it there, then come back. Done items drop off by themselves.',
                ],
                tips: [
                    'You only see lines for the parts of HQ your role covers.',
                    'Nothing waiting? The list says so. Check again after lunch.',
                ],
                try: { label: 'Open To do', href: '/hq/today' },
            },
            {
                id: 'numbers',
                title: 'Numbers',
                why: 'Monthly income, new brokerages, use and AI cost on one page.',
                steps: [
                    'Open the Numbers tab.',
                    'Compare this month with last month at the top.',
                    'Hover any chart for the figure behind it.',
                ],
                tips: [
                    'Totals only: no client or agent details are shown here.',
                ],
                try: { label: 'Open Numbers', href: '/hq/dashboard' },
            },
        ],
    },
    Customers: {
        title: 'Help brokerages get going',
        intro: 'Everyone who signs up, every brokerage on RightAlly, their first 30 days, Health Checks, and texting approvals.',
        start: [
            'Check Sign-ups for anyone who stopped before paying.',
            'Open Go-live and call the brokerages with late steps.',
            'Call the Health Checks marked "Wants a call".',
        ],
        lessons: [
            {
                id: 'brokerages',
                title: 'All brokerages',
                why: 'Find any brokerage and see its status, address and plan.',
                steps: [
                    'Search by name or address.',
                    'Open a brokerage to see its details.',
                    'Suspend, reactivate or close it from its page. Closing can be undone for 30 days.',
                ],
                tips: [
                    'Signing in as one of their users to help them is always recorded, and only roles allowed to do it can.',
                ],
                try: { label: 'Open all brokerages', href: '/hq/tenants' },
            },
            {
                id: 'signups',
                title: 'Sign-ups',
                why: 'People who started signing up but did not finish.',
                steps: [
                    'RightAlly sends them a reminder about an hour later and again a day later.',
                    'If they still have not finished, call or email them.',
                    'Failed sign-ups show what went wrong.',
                ],
                try: { label: 'Open sign-ups', href: '/hq/signups' },
            },
            {
                id: 'go-live',
                title: 'Go-live',
                why: 'The first 30 days decide whether a brokerage stays.',
                steps: [
                    'Each step ticks itself when the brokerage does it.',
                    'Late steps are shown first. Call those brokerages and help them finish.',
                ],
                try: { label: 'Open go-live', href: '/hq/go-live' },
            },
            {
                id: 'health-checks',
                title: 'Health Checks',
                why: 'Brokerages that ran the free Health Check on our website.',
                steps: [
                    'Only contact the ones marked "Wants a call".',
                    'The others asked for the report only. Leave them be.',
                ],
                try: { label: 'Open Health Checks', href: '/hq/health-checks' },
            },
            {
                id: 'texting',
                title: 'Texting approvals',
                why: 'Brokerages cannot send texts until the carriers approve them.',
                steps: [
                    "Open each brokerage's answers.",
                    'Submit them in the Twilio console (Trust Hub).',
                    'Come back and record what the carriers decided.',
                ],
                try: { label: 'Open texting approvals', href: '/hq/texting' },
            },
        ],
    },
    Support: {
        title: 'Answer brokerages quickly',
        intro: 'Help requests from every brokerage, the one-minute video for each page, and handwritten cards to send.',
        start: [
            'Answer the oldest requests in the Inbox first.',
            'Switch-on requests: switch the tool on in Settings › Feature switches, then answer.',
            'Send the handwritten cards that are waiting.',
        ],
        lessons: [
            {
                id: 'inbox',
                title: 'The Inbox',
                why: 'Your answer is emailed to the person and shown on their Help page.',
                steps: [
                    'Open a request and read it.',
                    'Write your answer in plain words.',
                    'Press Send answer.',
                ],
                try: { label: 'Open the Inbox', href: '/hq/support' },
            },
            {
                id: 'videos',
                title: 'Help videos',
                why: 'A one-minute video on a page saves a support request.',
                steps: [
                    'Record a short video showing the page.',
                    'Paste its YouTube, Vimeo or https MP4 link next to the page.',
                    'For a guide lesson, use the address guide:<menu>:<lesson>, for HQ guides guide:hq-<menu>:<lesson>.',
                ],
                try: { label: 'Open help videos', href: '/hq/support/videos' },
            },
            {
                id: 'cards',
                title: 'Handwritten cards',
                why: "Agents' cards are written and posted by our handwriting partner.",
                steps: [
                    'Download the list of cards waiting.',
                    'Upload it to the handwriting partner.',
                    'Mark the cards as mailed.',
                ],
                try: { label: 'Open cards', href: '/hq/cards' },
            },
        ],
    },
    Billing: {
        title: 'Price it right and get paid',
        intro: 'Packages and prices, RightAlly credits, the referral programme, and how money moves.',
        start: [
            'Build packages from modules and check the margin.',
            'Set list prices. Increases reach brokerages after 60 days.',
            'Check Credits so every package makes money on AI.',
        ],
        lessons: [
            {
                id: 'packages',
                title: 'Packages',
                why: 'See what a package costs us before we sell it.',
                steps: [
                    'Tick the modules in the package.',
                    'Read the cost and the price at our markup.',
                    'Hover the ⓘ on any number to see the sum behind it.',
                ],
                try: { label: 'Open packages', href: '/hq/packages' },
            },
            {
                id: 'pricing',
                title: 'Pricing',
                why: 'The public list price, and the range brokerages may charge.',
                steps: [
                    'Change the list price.',
                    'Brokerages hear about increases and get them after 60 days.',
                ],
                try: { label: 'Open pricing', href: '/hq/billing' },
            },
            {
                id: 'credits',
                title: 'Credits',
                why: 'What a credit sells for against what AI really costs.',
                steps: [
                    "Check each AI model's real cost per credit.",
                    'Make sure every package still makes money.',
                ],
                try: { label: 'Open credits', href: '/hq/credits' },
            },
            {
                id: 'payments',
                title: 'Payments and payout rails',
                why: 'Stripe for the platform, and how brokerages pay their agents.',
                steps: [
                    'Only Super admin can change payment keys.',
                    'Brokerages pay agents from their own bank. RightAlly never holds the money.',
                ],
                try: { label: 'Open payments', href: '/hq/payments' },
            },
        ],
        goodToKnow: [
            'Referral rewards are confirmed 30 days after the first payment. You pay them and mark them paid.',
        ],
    },
    'Websites and MLS': {
        title: 'Websites, content and MLS',
        intro: "RightAlly's own website, the articles AI writes for each state, brokerage websites, and the MLS feeds behind every listing search.",
        start: [
            'Approve or send back the articles waiting on the Content desk.',
            'Approve MLS access requests on MLS connections.',
            'Check IDX coverage for boards brokerages asked for.',
        ],
        lessons: [
            {
                id: 'website',
                title: 'RightAlly website',
                why: 'Our public website, checked for search and AI answers.',
                steps: [
                    'Edit a page as a private draft.',
                    'Preview it, read the search and AI-answer check, then publish.',
                    'Every version is kept, so you can go back.',
                ],
                try: { label: 'Open the website', href: '/hq/website' },
            },
            {
                id: 'content',
                title: 'Content desk',
                why: 'AI writes pages and articles for each state. Nothing goes out until a person approves it.',
                steps: [
                    'Read each draft, and edit it if needed.',
                    'Press "Approve and publish", or Reject.',
                    'More a week unlocks only when Google indexes the earlier pages.',
                ],
                ai: {
                    name: 'Content writer',
                    what: 'Writes pages and articles for broker owners and agents, state by state, for a person to approve.',
                },
                try: { label: 'Open the Content desk', href: '/hq/content' },
            },
            {
                id: 'mls',
                title: 'MLS connections',
                why: 'Each MLS feed is stored once and shared by every brokerage licensed for it.',
                steps: [
                    "Approve a brokerage's access once its MLS agreement checks out.",
                    'Keys are write-only: you can replace them, never read them.',
                ],
                try: { label: 'Open MLS connections', href: '/hq/mls' },
            },
            {
                id: 'idx',
                title: 'IDX coverage',
                why: 'Which states and boards are open, and the signed agreements.',
                steps: [
                    'Open a board request and add the board, or say when it will be ready.',
                    'Signed agreements are checked by the rules and AI. Read the check, then send it on or back.',
                ],
                ai: {
                    name: 'Agreement check',
                    what: "Reads a signed IDX agreement against the board's rules and writes a note on anything missing.",
                },
                try: { label: 'Open IDX coverage', href: '/hq/idx' },
            },
        ],
    },
    Movewise: {
        title: 'Run Movewise',
        intro: "HQ's consumer site. Each state goes live only with a broker of record, a signed-off legal checklist and a live MLS feed.",
        start: [
            'Check the launch list for the next state.',
            'Review new agents and their prices.',
            'Check introductions and settlements under Money.',
        ],
        lessons: [
            {
                id: 'launch',
                title: 'Launching a state',
                why: 'A state opens only when every check is green.',
                steps: [
                    'Open Launch.',
                    'Finish the red checks, including the legal checklist.',
                    'Go live only once every check is green.',
                ],
                try: { label: 'Open launch', href: '/hq/movewise/launch' },
            },
            {
                id: 'money',
                title: 'Money',
                why: 'Introductions, credits, referral invoices and partner settlements.',
                steps: [
                    'Check the last 90 days by state.',
                    'Follow up any unpaid invoice.',
                ],
                try: { label: 'Open money', href: '/hq/movewise/money' },
            },
        ],
    },
    Products: {
        title: 'Referral network and Studio',
        intro: 'How brokerages use the agent referral network, and what each Ally Studio package costs and earns.',
        start: [
            'Follow up open referral fee disputes.',
            'Check Studio margins after any price change.',
            'RightAlly never takes a share of referral fees.',
        ],
        lessons: [
            {
                id: 'network',
                title: 'Referral network',
                why: 'Disputes about referral fees need a quick, fair answer.',
                steps: ['Open a dispute.', 'Read both sides and contact them.'],
                try: { label: 'Open referral network', href: '/hq/network' },
            },
        ],
    },
    AI: {
        title: 'Keep AI right and affordable',
        intro: 'Which AI models answer which features, whether the answers are good, and what it all costs.',
        start: [
            'Check Quality after any model change.',
            'Check Cost each week.',
            'Change routes on the Gateway only after Test now passes.',
        ],
        lessons: [
            {
                id: 'gateway',
                title: 'Gateway',
                why: 'Providers, models and routes for every AI feature.',
                steps: [
                    'Pick the model for a feature.',
                    'Then press Test now on the Quality tab.',
                ],
                try: { label: 'Open the Gateway', href: '/hq/ai' },
            },
            {
                id: 'quality',
                title: 'Quality',
                why: 'Every AI feature is tested every Monday against realistic cases.',
                steps: [
                    'Look for red results.',
                    'Press Test now after you change anything.',
                ],
                tips: [
                    'The tests check facts, Fair Housing, length and that nothing is made up.',
                ],
                try: { label: 'Open quality', href: '/hq/ai/quality' },
            },
            {
                id: 'cost',
                title: 'Cost',
                why: 'What AI costs RightAlly, by feature and brokerage.',
                steps: ['Compare this week with last week.'],
                tips: [
                    "No one's questions or answers are stored, so nothing here shows what anyone asked.",
                ],
                try: { label: 'Open cost', href: '/hq/ai/cost' },
            },
        ],
    },
    Connections: {
        title: 'Outside services and apps',
        intro: 'The keys for outside services, texts and calls, the apps brokers connect, and partner apps.',
        start: [
            'Turn a service on in Test first, check it works, then switch to Live.',
            'Only Super admin can change keys.',
            'Check App demand to see what brokerages want next.',
        ],
        lessons: [
            {
                id: 'services',
                title: 'Outside services',
                why: 'Every outside service has Off, Test and Live.',
                steps: [
                    'Pick the provider.',
                    'Type the keys yourself. They are saved encrypted and never shown again.',
                    'Try Test, then switch to Live.',
                ],
                try: { label: 'Open outside services', href: '/hq/services' },
            },
            {
                id: 'apps',
                title: 'App demand',
                why: 'How many brokerages asked for each app.',
                steps: ['Build the most wanted apps first.'],
                try: { label: 'Open app demand', href: '/hq/apps' },
            },
        ],
    },
    System: {
        title: 'Keep RightAlly running',
        intro: 'Watchtower watches errors, failed jobs, slow pages and downtime. Cells shows how full each database is.',
        start: [
            'Open Watchtower and deal with serious problems first.',
            'Paste the fix request into an engineering task.',
            'Check Cells when you get a "nearly full" email.',
        ],
        lessons: [
            {
                id: 'watchtower',
                title: 'Watchtower',
                why: 'Problems are found and explained before brokerages notice.',
                steps: [
                    'Open a problem to see how often it happens and since which release.',
                    'Read the likely root cause.',
                    'Press Mark fixed once the fix is released. If it comes back, Watchtower says so.',
                ],
                ai: {
                    name: 'Root-cause finder',
                    what: 'Reads the error and our code and suggests the likely cause and a fix to paste.',
                },
                try: { label: 'Open Watchtower', href: '/hq/watchtower' },
            },
            {
                id: 'cells',
                title: 'Cells',
                why: 'Each cell is a database holding many brokerages.',
                steps: [
                    'Watch the percent full.',
                    'A full cell closes itself to new brokerages and reopens when space frees up.',
                ],
                try: { label: 'Open cells', href: '/hq/cells' },
            },
        ],
    },
    Settings: {
        title: 'Your HQ team',
        intro: 'Who works in HQ, what each person may see and change, security evidence, and feature switches.',
        start: [
            'Add each HQ person under HQ team.',
            'Give each person one role under Roles.',
            'Keep at least one other Super admin.',
        ],
        lessons: [
            {
                id: 'roles',
                title: 'Roles',
                why: 'People only see the parts of HQ their role covers.',
                steps: [
                    'Start from one of the seven ready-made roles.',
                    'Or make your own: for each part of HQ choose No access, See, or See and change.',
                    'Give each person one role.',
                ],
                tips: [
                    'Payment keys, outside-service keys, roles and data exports are Super admin only.',
                ],
                try: { label: 'Open roles', href: '/hq/roles' },
            },
            {
                id: 'switches',
                title: 'Feature switches',
                why: 'Switch an area on or off for everyone, a share of brokerages, or one brokerage.',
                steps: [
                    "A brokerage's own setting wins over the everyone setting.",
                    'Roll out new areas to a small share first.',
                ],
                try: { label: 'Open feature switches', href: '/hq/switches' },
            },
        ],
    },
};

const CHECK =
    'Always read what the AI wrote before it goes out: a person approves every step.';

/** HQ4: one tip per HQ page, and the AI marker where AI does work on the page. */
export const HQ_PAGE_HINTS: Record<string, PageHint> = {
    '/hq/today': {
        tip: 'Each line drops off by itself once it is dealt with.',
    },
    '/hq/dashboard': {
        tip: 'Totals only. Open a brokerage under Customers for its details.',
    },
    '/hq/tenants': {
        tip: 'Closing a brokerage can be undone for 30 days.',
    },
    '/hq/signups': {
        tip: 'Reminders go out by themselves after an hour and after a day.',
    },
    '/hq/go-live': {
        tip: 'Call the brokerages with late steps first.',
    },
    '/hq/health-checks': {
        tip: 'Only contact the ones marked "Wants a call".',
    },
    '/hq/texting': {
        tip: "Record the carriers' answer here as soon as you get it, so the brokerage can start texting.",
    },
    '/hq/support': {
        tip: 'Answer in plain words; your reply is emailed and shown on their Help page.',
    },
    '/hq/support/videos': {
        tip: 'About a minute per video is enough.',
    },
    '/hq/cards': { tip: 'Mark cards as mailed once the partner has them.' },
    '/hq/packages': { tip: 'Hover the ⓘ on any number to see the sum.' },
    '/hq/billing': {
        tip: 'Price increases reach brokerages 60 days after you save them.',
    },
    '/hq/credits': {
        tip: 'Check this page after any AI model or price change.',
    },
    '/hq/payments': { tip: 'Only Super admin can change payment keys.' },
    '/hq/website': {
        ai: {
            name: 'Search and AI-answer check',
            does: 'Checks every page for search engines and AI answers before you publish it.',
            most: 'Fix the red items in the check before you publish.',
            lesson: 'website',
        },
        tip: 'Pages are private drafts until you publish them.',
    },
    '/hq/content': {
        ai: {
            name: 'Content writer',
            does: 'Writes pages and articles for broker owners and agents, state by state.',
            most: CHECK,
            lesson: 'content',
        },
        tip: 'More a week unlocks only when Google indexes the earlier pages.',
    },
    '/hq/mls': {
        tip: 'Keys are write-only: you can replace them, never read them.',
    },
    '/hq/idx': {
        ai: {
            name: 'Agreement check',
            does: "Reads a signed IDX agreement against the board's rules and writes a note on anything missing.",
            most: 'Read the note and add anything of your own before you send it back.',
            lesson: 'idx',
        },
        tip: 'Brokers and agents see board names and fees only, never connector names.',
    },
    '/hq/movewise/launch': {
        tip: 'A state goes live only when every check is green.',
    },
    '/hq/network': { tip: 'RightAlly takes no share of referral fees.' },
    '/hq/ai/quality': { tip: 'Press Test now after you change anything.' },
    '/hq/ai/cost': {
        tip: "No one's questions or answers are stored, so nothing here shows what anyone asked.",
    },
    '/hq/services': {
        tip: 'Try Test first, check it works, then switch to Live.',
    },
    '/hq/watchtower': {
        ai: {
            name: 'Root-cause finder',
            does: 'Reads the error and our code and suggests the likely cause and a fix request to paste.',
            most: 'Check the suggested cause against the steps that led to the error before anyone changes code.',
            lesson: 'watchtower',
        },
        tip: 'Deal with serious problems first.',
    },
    '/hq/cells': {
        tip: 'A full cell closes itself to new brokerages and reopens when space frees up.',
    },
    '/hq/roles': {
        tip: 'Keep at least two people as Super admin, in case one is away.',
    },
    '/hq/switches': {
        tip: "A brokerage's own setting wins over the everyone setting.",
    },
};
