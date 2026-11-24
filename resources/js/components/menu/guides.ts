/**
 * MN3: the help guide behind each main menu's button ("Close more deals", "Pay agents right, on time"…). Plain words
 * for people who are not technical: why each part matters, how to do it step by step, the AI that can do the work,
 * and a link to try it. Every lesson has a spot for a 60-second video; HQ adds the video under Help videos with the
 * address `guide:<menu>:<lesson>` (for example `guide:crm:new-leads`, `guide:website-idx:builder`) and it plays here.
 */

export type Lesson = {
    id: string;
    title: string;
    /** One line: what you get out of it. */
    why: string;
    steps: string[];
    ai?: { name: string; what: string; example?: string };
    tips?: string[];
    try?: { label: string; href: string };
};

export type Guide = {
    title: string;
    intro: string;
    /** Exactly three first steps. */
    start: [string, string, string];
    lessons: Lesson[];
    goodToKnow?: string[];
};

const ASK = 'Your AI Assistant';

const OWNER: Record<string, Guide> = {
    Dashboard: {
        title: 'Plan a winning day',
        intro: 'Your dashboard shows what needs you today: the people to call, the deals with dates coming up, and the tasks that are due. Spend two minutes here each morning and nothing slips.',
        start: [
            'Open the Dashboard first thing each morning.',
            'Work through "Today\'s 10": the ten people most worth a call today.',
            'Clear the tasks that are due, then check Team chat.',
        ],
        lessons: [
            {
                id: 'today',
                title: 'Your 10 calls for today',
                why: 'The ten people most likely to do business with you, picked for you every morning.',
                steps: [
                    'Open the "Today\'s 10" tab.',
                    'Each person shows why they are on the list, for example "looked at 3 homes last night" or "home anniversary this week".',
                    'Press Call, Text or Email next to a name. What you do is saved on their record by itself.',
                    'Press "Done" or "Not today" so tomorrow\'s list stays fresh.',
                ],
                ai: {
                    name: 'Lead-IQ',
                    what: "Reads every contact's website visits, emails and past deals overnight and picks who to call. It also writes a short opener you can use.",
                    example:
                        'Who should I call first today, and what should I say?',
                },
                tips: [
                    'Ten good calls a day is about 200 a month. That is enough for most agents to close one or two more deals.',
                    'Do the calls before you open email.',
                ],
                try: { label: "Open today's 10", href: '/today' },
            },
            {
                id: 'game-plan',
                title: 'Your game plan',
                why: 'Set a goal for the year and see each week whether you are on track.',
                steps: [
                    'Open "Game plan".',
                    'Type how much you want to earn or how many deals you want to close this year.',
                    'RightAlly works backwards: how many appointments and calls a week that needs.',
                    'Check the green or red bar each Monday.',
                ],
                ai: {
                    name: ASK,
                    what: 'Turns your goal into a weekly plan and tells you how far ahead or behind you are.',
                    example: 'Am I on track for my goal this month?',
                },
                try: { label: 'Set my goal', href: '/engage' },
            },
            {
                id: 'tasks',
                title: 'Tasks and reminders',
                why: 'One list for everything you promised to do, from every deal and every client.',
                steps: [
                    'Open the "Tasks" tab. Tasks from your deals and contacts appear here by themselves.',
                    'Press "Add a task" for anything else. Give it a date so it reminds you.',
                    'Tick a task when it is done.',
                ],
                ai: {
                    name: ASK,
                    what: 'Adds tasks for you when you say them out loud.',
                    example: 'Remind me to call the Garcias on Friday at 10.',
                },
                try: { label: 'Open my tasks', href: '/tasks' },
            },
            {
                id: 'chat',
                title: 'Team chat',
                why: 'Talk with your team inside RightAlly instead of scattered text threads.',
                steps: [
                    'Open "Team chat".',
                    'Pick a person or a group.',
                    'Type, or attach a file or photo.',
                ],
                tips: [
                    'Keep client documents in the deal, not in chat, so they stay with the file.',
                ],
                try: { label: 'Open Team chat', href: '/chat' },
            },
        ],
        goodToKnow: [
            'Everything on the dashboard links to the full page. Click any number to see the list behind it.',
        ],
    },

    CRM: {
        title: 'Close more deals',
        intro: 'CRM is everyone you are working with until they go under contract: new leads, past clients, your sphere. The money in real estate is in answering fast and following up long after other agents give up. This menu does both for you.',
        start: [
            'Bring in your contacts: press "Import contacts" and choose your spreadsheet, phone or old CRM.',
            'Turn on Lead-IQ so every new lead is answered within a minute.',
            'Open the Leads tab each morning and call anyone marked "Not contacted".',
        ],
        lessons: [
            {
                id: 'new-leads',
                title: 'Answer every new lead within a minute',
                why: 'The first agent to reply wins most leads. Lead-IQ replies for you, day and night.',
                steps: [
                    'Open the Leads tab. New leads from your website, portals and Movewise arrive here by themselves.',
                    'Turn on Lead-IQ from the banner at the top of the page.',
                    'Choose what it may do: text only, or text and call.',
                    'When a lead answers, you get a short summary and a "Call now" button.',
                ],
                ai: {
                    name: 'Lead-IQ',
                    what: 'Texts or calls new leads in your name, asks what they are looking for and their timing, and books a time with you. It only contacts people who gave written consent, and only between 8am and 9pm their time.',
                    example: 'Which new leads have not been answered yet?',
                },
                tips: [
                    'Leads that are not answered in 5 minutes are far less likely to ever reply.',
                    'Read the Brief before you call. It tells you what they looked at and what they want.',
                ],
                try: { label: 'Open Leads', href: '/leads' },
            },
            {
                id: 'contacts',
                title: 'Keep your whole database in one place',
                why: 'Your past clients and sphere are where most repeat and referral business comes from.',
                steps: [
                    'Open the Contacts tab.',
                    'Press "Import contacts" and pick your file. RightAlly finds the name, phone and email columns for you.',
                    'Duplicates are merged and bad emails are flagged automatically.',
                    'Open anyone to see every call, email, showing and deal in one timeline.',
                ],
                ai: {
                    name: ASK,
                    what: 'Finds people for you in plain words.',
                    example:
                        'Show me past clients who bought more than 5 years ago.',
                },
                tips: [
                    'Check "Database health" once a month. It lists missing phones, bad emails and people nobody has called in a year.',
                ],
                try: { label: 'Open Contacts', href: '/contacts' },
            },
            {
                id: 'inbox',
                title: 'Every message in one inbox',
                why: 'Texts, emails and website chats in one list, so nobody waits for an answer.',
                steps: [
                    'Open the Inbox tab.',
                    'Unread messages are at the top.',
                    'Reply right there. The message goes out from your number or email.',
                    'Assign a conversation to a teammate if it is theirs.',
                ],
                ai: {
                    name: ASK,
                    what: 'Drafts replies in your voice for you to check and send.',
                    example: "Draft a reply to Maria about Saturday's showing.",
                },
                try: { label: 'Open Inbox', href: '/inbox' },
            },
            {
                id: 'appointments',
                title: 'Appointments and showings',
                why: 'Let clients book a time that suits you, with reminders that cut no-shows.',
                steps: [
                    'Open the Appointments tab.',
                    'Set your hours under Settings › My account › Availability.',
                    'Share your booking link, or add an appointment yourself.',
                    'Clients get a reminder the day before and an hour before.',
                ],
                try: { label: 'Open Appointments', href: '/appointments' },
            },
            {
                id: 'campaigns',
                title: 'Follow up for months without thinking about it',
                why: 'Most buyers buy 6 to 18 months after they first ask. Campaigns keep you in touch the whole time.',
                steps: [
                    'Open the Campaigns tab.',
                    'Pick a ready-made campaign, for example "New buyer lead, 12 months".',
                    'Read the messages and change any words you like.',
                    'Add people to it. Their replies come to your Inbox and the campaign pauses for them.',
                ],
                ai: {
                    name: ASK,
                    what: 'Writes a new campaign from a short description.',
                    example:
                        'Write a 6-email campaign for people thinking of selling next spring.',
                },
                tips: ['Texts only go to people who agreed to receive them.'],
                try: { label: 'Open Campaigns', href: '/outreach' },
            },
            {
                id: 'movewise',
                title: 'Movewise leads',
                why: "People moving to your area, sent to you by RightAlly's Movewise service.",
                steps: [
                    'Open the Movewise leads tab.',
                    "Read the family's summary: where they are moving from, timing and budget.",
                    'Press Accept within the time shown, or the lead goes to the next agent.',
                    'Accepted leads move to your Leads list.',
                ],
                try: { label: 'Open Movewise leads', href: '/movewise' },
            },
        ],
        goodToKnow: [
            'CRM is for people before they go under contract. When a deal is signed, it moves to Transactions.',
            "Your brokerage's leads are never sold or shared with anyone else.",
        ],
    },

    Listings: {
        title: 'Win more listings',
        intro: 'Everything for the homes you sell: search the MLS, price a home with confidence, show sellers the buyer demand, and keep your own listings in one board.',
        start: [
            'Connect your MLS under Website & IDX › MLS / IDX (one time).',
            'Open "CMA and estimates" before your next listing appointment.',
            'Add your active listings to "My listings".',
        ],
        lessons: [
            {
                id: 'search',
                title: 'Search the MLS',
                why: 'Find homes for buyers and comparables for sellers without leaving RightAlly.',
                steps: [
                    'Open the MLS search tab.',
                    'Type an area, a price range and beds.',
                    'Save the search to a client. They get an email when a new match comes on.',
                ],
                ai: {
                    name: ASK,
                    what: 'Searches in plain words.',
                    example:
                        'Find 3-bed homes under $450k in the Heights with a yard.',
                },
                try: { label: 'Search the MLS', href: '/listings' },
            },
            {
                id: 'pricing',
                title: 'Price a home with confidence',
                why: 'Walk into the appointment with a price you can explain and defend.',
                steps: [
                    'Open the CMA and estimates tab.',
                    'Type the address.',
                    'Check the comparables. Untick any that are not a fair match.',
                    'Print or send the seller the one-page price report.',
                ],
                ai: {
                    name: 'List-IQ',
                    what: 'Picks the closest comparables, adjusts for size and condition, and suggests a price range with the reasons.',
                    example: 'What should 412 Oak St list for?',
                },
                tips: [
                    'Show the seller the "Buyer demand" page too. It shows how many buyers are looking in their price range.',
                ],
                try: { label: 'Price a home', href: '/pricing' },
            },
            {
                id: 'my-listings',
                title: 'Your listings board',
                why: 'Every listing from coming soon to sold, with what needs doing next.',
                steps: [
                    'Open the My listings tab.',
                    'Press "Add a listing" or bring it in from the MLS.',
                    'Drag a listing to its new stage when it changes.',
                    'Under contract? Press "Start the transaction" to move it to Transactions.',
                ],
                ai: {
                    name: 'List-IQ',
                    what: 'Writes the listing description, the photo captions and the social posts.',
                    example: 'Write a listing description for 412 Oak St.',
                },
                try: { label: 'Open My listings', href: '/deals/listings' },
            },
            {
                id: 'demand',
                title: 'Show sellers the buyer demand',
                why: 'Sellers choose the agent who can prove there are buyers.',
                steps: [
                    'Open the Buyer demand tab.',
                    'Pick the area and price range.',
                    'Share the page with the seller.',
                ],
                try: { label: 'See buyer demand', href: '/listings/demand' },
            },
        ],
    },

    Transactions: {
        title: 'Close error-free files',
        intro: 'Transactions is every deal from the signed contract to the closing table and the commission check. RightAlly reads the contract, sets the dates, builds the checklist and reminds everyone, so files are complete and nothing is missed.',
        start: [
            'Start a deal from the contract: press "Start from a contract" and upload the signed file or a photo.',
            'Check the dates RightAlly found and press "Set it up".',
            'Look at "At risk today" each morning and clear anything red.',
        ],
        lessons: [
            {
                id: 'start',
                title: 'Start a deal in one minute',
                why: 'No more typing in names, prices and dates by hand.',
                steps: [
                    'Open the All transactions tab.',
                    'Press "Start from a contract" and choose the signed contract (a file or a phone photo). You can also describe it out loud, or forward the contract by email.',
                    'RightAlly fills in the buyers, sellers, price, closing date and every deadline.',
                    'Check each one, change anything that is wrong, and press "Set it up".',
                ],
                ai: {
                    name: 'Deal-IQ',
                    what: 'Reads the contract, finds every party and date, and builds the deal with its checklist and reminders.',
                    example:
                        'Buyer deal, the Garcias, 412 Oak St Austin TX, $450k, closing Dec 18.',
                },
                tips: [
                    'Always check the dates once. You are still responsible for them.',
                ],
                try: { label: 'Start a deal', href: '/deals' },
            },
            {
                id: 'risk',
                title: 'Catch problems before they cost you',
                why: 'Missed deadlines are the most common reason deals fall apart.',
                steps: [
                    'Open the Risk tab, or look at "At risk today" on All transactions.',
                    'Red means a date is in the next three days or something is blocking the deal.',
                    'Open the deal and press the item to fix it, or assign it to someone.',
                    'Turn on text or call reminders if you want them (8am to 9pm only).',
                ],
                ai: {
                    name: 'Deal-IQ',
                    what: 'Watches every date and document and warns you before something is late.',
                    example: 'What is due on my deals this week?',
                },
                try: { label: 'See deals at risk', href: '/deals/risk' },
            },
            {
                id: 'documents',
                title: 'Complete files, checked for you',
                why: 'A full, correct file protects your licence and gets you paid faster.',
                steps: [
                    'Open a deal and go to its Documents.',
                    'Drop in each signed document. RightAlly files it against the right checklist item.',
                    'Missing signatures, initials or pages are flagged in red.',
                    'When everything is green, press "Send for review".',
                ],
                ai: {
                    name: 'DocIQ',
                    what: 'Reads each document, checks signatures, initials and dates, and tells you what is missing.',
                    example: 'What is missing from the Garcia file?',
                },
                try: { label: 'Open Checklists', href: '/deals/compliance' },
            },
            {
                id: 'review',
                title: 'Compliance review',
                why: 'The broker approves each file before closing, in one place.',
                steps: [
                    'Open the Compliance review tab.',
                    'Files waiting for you are at the top.',
                    'Open a file, check the items, and press Approve or "Send back" with a note.',
                    'The agent is told straight away.',
                ],
                try: { label: 'Open Compliance review', href: '/deals/review' },
            },
            {
                id: 'forms',
                title: 'Forms and e-signatures',
                why: 'Send documents for signature and get them back the same day.',
                steps: [
                    'Open the Forms and e-sign tab.',
                    'Pick a form or upload your own.',
                    'Add who signs and where.',
                    'Press Send. Signed copies are saved to the deal.',
                ],
                try: {
                    label: 'Open Forms and e-sign',
                    href: '/forms/templates',
                },
            },
            {
                id: 'money',
                title: 'Money coming in',
                why: 'See the company dollar you can expect in the next 30, 60 and 90 days.',
                steps: [
                    'Open the Money coming in tab.',
                    'Each deal shows the expected commission and closing date.',
                    'At closing, the commission goes to Commissions to pay agents.',
                ],
                try: { label: 'See money coming in', href: '/deals/money' },
            },
        ],
        goodToKnow: [
            'Deals before a signed contract are in CRM. Transactions starts at the signed contract.',
            'Every change on a deal is recorded with who did it and when.',
        ],
    },

    Marketing: {
        title: 'Get seen everywhere',
        intro: 'Make posts, ads, postcards and landing pages in your brand in minutes, and keep sellers and buyers seeing your name.',
        start: [
            'Set your brand colours, logo and voice once (Brand voice tab).',
            'Make your first post in Ally Studio.',
            'Connect Facebook, Instagram and LinkedIn in Social posts.',
        ],
        lessons: [
            {
                id: 'studio',
                title: 'Make anything in Studio',
                why: 'Professional flyers, posts and videos without a designer.',
                steps: [
                    'Open the Ally Studio tab.',
                    'Pick what you want to make: a post, a flyer, a "Just sold" card, a video.',
                    'Choose a listing or type a message.',
                    'Download it or post it.',
                ],
                ai: {
                    name: ASK,
                    what: 'Writes the words and picks the photos for you.',
                    example: 'Make a "Just listed" post for 412 Oak St.',
                },
                try: { label: 'Open Studio', href: '/studio' },
            },
            {
                id: 'social',
                title: 'Post to social media on a schedule',
                why: 'Stay visible every week without logging in to every app.',
                steps: [
                    'Open the Social posts tab.',
                    'Connect your accounts once.',
                    'Write or pick a post and choose the days.',
                    'RightAlly posts it for you.',
                ],
                try: { label: 'Open Social', href: '/social' },
            },
            {
                id: 'ads',
                title: 'Ads and landing pages',
                why: 'Bring in new buyer and seller leads straight into your CRM.',
                steps: [
                    'Open the Ads tab, or Funnels for a landing page.',
                    'Pick a goal: buyers, sellers or home values.',
                    'Set the area and budget.',
                    'Leads land in CRM and Lead-IQ answers them.',
                ],
                try: { label: 'Open Ads', href: '/ads' },
            },
            {
                id: 'reviews',
                title: 'Collect reviews',
                why: 'Reviews are the first thing new clients look at.',
                steps: [
                    'Open the Reviews tab.',
                    'After each closing, RightAlly asks the client for a review.',
                    'Good reviews show on your website.',
                ],
                try: { label: 'Open Reviews', href: '/reviews' },
            },
            {
                id: 'check',
                title: 'Marketing check',
                why: 'Make sure every ad and post follows fair-housing and advertising rules.',
                steps: [
                    'Open the Marketing check tab.',
                    'Paste the words or upload the image.',
                    'Fix anything flagged before you publish.',
                ],
                try: { label: 'Check my marketing', href: '/marketing-check' },
            },
        ],
    },

    'Website & IDX': {
        title: 'Turn visitors into leads',
        intro: 'Your brokerage website with live MLS listings, a blog, area pages and a website assistant that answers visitors and captures their details. Every lead goes straight to your CRM.',
        start: [
            'Connect your MLS board in the MLS / IDX tab.',
            'Pick a design and add your logo in the Website builder.',
            'Turn on the Website assistant so visitors get answers day and night.',
        ],
        lessons: [
            {
                id: 'builder',
                title: 'Build your website',
                why: 'A fast, modern website that ranks on Google and turns visitors into leads.',
                steps: [
                    'Open the Website builder tab.',
                    'Pick a design.',
                    'Add your logo, photos and words. Changes show in the preview.',
                    'Press Publish. Your domain is connected for you.',
                ],
                try: {
                    label: 'Open the Website builder',
                    href: '/settings/website',
                },
            },
            {
                id: 'mls',
                title: 'Show live MLS listings',
                why: 'Buyers search on your site instead of Zillow, and you get the lead.',
                steps: [
                    'Open the MLS / IDX tab.',
                    'Pick your board or boards.',
                    'Fill in your board details and press "Send to the MLS".',
                    'Listings appear on your site once the board approves.',
                ],
                try: { label: 'Connect MLS', href: '/settings/mls' },
            },
            {
                id: 'blog',
                title: 'Blog and area pages',
                why: 'Helpful local articles bring free visitors from Google for years.',
                steps: [
                    'Open the Blog tab.',
                    'Press "Write with AI" and type a topic, or write your own.',
                    'Add Communities pages for the areas you serve.',
                ],
                ai: {
                    name: 'Blog writer',
                    what: 'Writes local articles in your voice, ready for Google.',
                    example:
                        'Write a guide to living in the Heights for first-time buyers.',
                },
                try: { label: 'Open the Blog', href: '/settings/website/blog' },
            },
            {
                id: 'assistant',
                title: 'Website assistant',
                why: "Answers visitors' questions at any hour and collects their contact details.",
                steps: [
                    'Open the Website assistant tab.',
                    'Turn it on.',
                    'Add any answers you want it to give (fees, areas, hours).',
                    'Conversations and leads go to your CRM Inbox.',
                ],
                try: {
                    label: 'Set up the Website assistant',
                    href: '/settings/ailly',
                },
            },
        ],
    },

    Agents: {
        title: 'Grow and keep your team',
        intro: 'Everyone on your team in one place: invite agents, keep licences up to date, recruit new agents and spot anyone who may leave.',
        start: [
            'Invite your agents in the People tab.',
            'Check licences and onboarding in "Licences and onboarding".',
            'Look at Retention once a month.',
        ],
        lessons: [
            {
                id: 'people',
                title: 'Invite and manage your agents',
                why: 'Each agent gets their own login, website and back office.',
                steps: [
                    'Open the People tab.',
                    'Press "Invite" and type their name and email.',
                    'Choose their role and commission plan.',
                    'They get a welcome email to set their password.',
                ],
                try: { label: 'Open People', href: '/users' },
            },
            {
                id: 'licences',
                title: 'Licences and onboarding',
                why: 'Never let an agent work with an expired licence.',
                steps: [
                    'Open the Licences and onboarding tab.',
                    'Each agent shows their licence number and expiry date.',
                    'You and the agent are reminded before it expires.',
                ],
                try: { label: 'Open Licences and onboarding', href: '/roster' },
            },
            {
                id: 'recruiting',
                title: 'Recruit agents',
                why: 'Find agents who are producing in your area and invite them.',
                steps: [
                    'Open the Recruiting tab.',
                    'Look at agents by their sales in your area.',
                    'Add them to a recruiting campaign.',
                ],
                ai: {
                    name: ASK,
                    what: 'Finds agents worth talking to.',
                    example:
                        'Show me agents with 10+ sales last year in Austin.',
                },
                try: { label: 'Open Recruiting', href: '/recruiting' },
            },
            {
                id: 'retention',
                title: 'Keep your best agents',
                why: 'It costs far less to keep a good agent than to find a new one.',
                steps: [
                    'Open the Retention tab.',
                    'Agents whose activity has dropped are flagged.',
                    'Call them or book a coaching session.',
                ],
                try: { label: 'Open Retention', href: '/recruiting/retention' },
            },
            {
                id: 'training',
                title: 'Training',
                why: 'Short lessons that help new agents get their first deal faster.',
                steps: [
                    'Open the Training tab.',
                    'Assign a course to an agent or the whole team.',
                    'See who has finished.',
                ],
                ai: {
                    name: 'Learn-IQ',
                    what: 'Picks the next lesson for each agent based on what they are doing.',
                    example:
                        'Which agents have not finished onboarding training?',
                },
                try: { label: 'Open Training', href: '/learn' },
            },
        ],
    },

    Commissions: {
        title: 'Pay agents right, on time',
        intro: 'Set up how each agent is paid, from simple splits to caps, fees, ranks and revenue share, then pay everyone correctly when a deal closes. Agents see the same numbers you do.',
        start: [
            'Create your commission plans in the Commission plans tab, and give each agent a plan.',
            'Set up your ranks so agents can move up by what they close and who they bring in.',
            'When a deal closes, check the payout and press Pay.',
        ],
        lessons: [
            {
                id: 'plans',
                title: 'Commission plans',
                why: "Every agent's split, cap and fees written down once, used on every deal.",
                steps: [
                    'Open the Commission plans tab.',
                    'Press "New plan" or copy a ready-made one.',
                    'Set the split (for example 70/30), the cap and any fees.',
                    'Assign the plan to agents.',
                ],
                tips: ['Test a plan in the Simulator before you assign it.'],
                try: {
                    label: 'Open Commission plans',
                    href: '/compensation/plans',
                },
            },
            {
                id: 'ranks',
                title: 'Ranks: let agents move up',
                why: 'Agents earn a higher rank by what they close and the agents they bring in.',
                steps: [
                    'Open the Ranks tab.',
                    'Press "Start from suggested ranks", then change the names and numbers to fit your brokerage.',
                    'For each rank, set what it takes (closed deals or GCI, and recruits who closed a deal) and what it gives (revenue-share levels, and a better commission plan if you like).',
                    'Press "Save ranks". Each agent\'s rank and what they still need appear at the bottom of the page.',
                    'When an agent reaches a rank with a better plan, they appear under "Ready to move up". Press "Move to" when you agree. Nothing changes by itself.',
                ],
                tips: [
                    'Agents see their own rank and what the next one needs on their Revenue share page, which keeps them motivated.',
                ],
                try: { label: 'Open Ranks', href: '/compensation/ranks' },
            },
            {
                id: 'network',
                title: 'Your network tree',
                why: "See who brought in whom, with each agent's rank, deals and team size.",
                steps: [
                    'Open the Network tree tab.',
                    'People who joined on their own are at the top; the agents they brought in sit below them.',
                    'Press the arrow next to a name to open their team, or type a name to find someone.',
                    'To correct who brought someone in, press "Change who brought them in", choose the person and write why. It applies from today.',
                ],
                tips: [
                    'Revenue share follows this tree, level by level. Deals already closed keep the revenue share they paid.',
                ],
                try: {
                    label: 'Open the Network tree',
                    href: '/compensation/network',
                },
            },
            {
                id: 'revenue-share',
                title: 'Revenue share',
                why: 'Reward agents who bring other agents to your brokerage.',
                steps: [
                    'Open the Revenue share tab.',
                    'Set how many levels and the percent for each.',
                    'Each closing shares the amount automatically.',
                ],
                try: {
                    label: 'Open Revenue share',
                    href: '/compensation/revenue-share',
                },
            },
            {
                id: 'simulator',
                title: 'Try a plan before you use it',
                why: 'See what an agent and the brokerage would earn, before you change anything.',
                steps: [
                    'Open the Simulator tab.',
                    'Pick a plan and type a sale price.',
                    "See the agent's pay, the brokerage share and the fees.",
                ],
                try: {
                    label: 'Open the Simulator',
                    href: '/compensation/simulator',
                },
            },
            {
                id: 'payouts',
                title: 'Pay agents',
                why: 'Correct payouts, the same day the deal closes.',
                steps: [
                    'Open the Payouts tab.',
                    'Closed deals waiting to be paid are listed.',
                    'Check the breakdown and press Pay.',
                    'The agent gets a statement by email.',
                ],
                try: { label: 'Open Payouts', href: '/finance/payouts' },
            },
        ],
        goodToKnow: [
            'Payouts and bank details can only be changed by people you allow, after confirming their password.',
        ],
    },

    Accounting: {
        title: 'Keep books audit-ready',
        intro: 'Your brokerage books: money in and out, trust accounts, bank matching, tax and monthly statements. Commissions and fees are recorded here automatically.',
        start: [
            'Connect your bank in Books › Bank reconciliation.',
            'Check money in and out each week.',
            'Close the month in Books › Periods.',
        ],
        lessons: [
            {
                id: 'money',
                title: 'Money in and out',
                why: 'See every receipt, bill and agent charge in one place.',
                steps: [
                    'Open the Money in and out section and its Money received tab.',
                    'Receipts from closings appear by themselves.',
                    'Add bills and charge agents their fees.',
                ],
                try: { label: 'Open Receipts', href: '/finance/receipts' },
            },
            {
                id: 'bank',
                title: 'Match your bank',
                why: 'Books that match the bank are books you can trust.',
                steps: [
                    'Open Books › Bank reconciliation.',
                    'Bank lines are matched to your records automatically.',
                    'Check anything left unmatched.',
                ],
                try: { label: 'Open Bank', href: '/finance/bank' },
            },
            {
                id: 'trust',
                title: 'Trust accounts',
                why: 'Earnest money handled exactly as the law requires.',
                steps: [
                    'Open Trust, tax and audit › Trust reconciliation.',
                    'Record deposits and releases against each deal.',
                    'Print the trust report for your regulator.',
                ],
                try: { label: 'Open Trust', href: '/finance/trust' },
            },
            {
                id: 'statements',
                title: 'Monthly statements',
                why: 'Profit and loss and balance sheet, ready for your accountant.',
                steps: [
                    'Open Trust, tax and audit › Profit and loss.',
                    'Pick the month.',
                    'Download or send to your accountant.',
                ],
                try: { label: 'Open Statements', href: '/finance/statements' },
            },
        ],
    },

    Reports: {
        title: 'Know your numbers',
        intro: 'See how the business is doing: sales, sources of business, agent production and profit, with no spreadsheet work.',
        start: [
            'Open Reports once a week.',
            'Look at where your closed deals came from.',
            'Check profit per agent each month.',
        ],
        lessons: [
            {
                id: 'overview',
                title: 'The business at a glance',
                why: 'Sales, pending deals and income this month compared with last year.',
                steps: [
                    'Open the Numbers tab.',
                    'Pick the date range.',
                    'Click any number to see the deals behind it.',
                ],
                ai: {
                    name: ASK,
                    what: 'Answers questions about your numbers.',
                    example:
                        'How many deals did we close last quarter, and from which sources?',
                },
                try: { label: 'Open Reports', href: '/insights' },
            },
            {
                id: 'reports',
                title: 'Ready-made reports',
                why: 'The reports brokers use most, ready to download.',
                steps: [
                    'Open the Reports tab.',
                    'Pick a report.',
                    'Download as a spreadsheet or schedule it by email.',
                ],
                try: { label: 'Open Reports', href: '/insights/reports' },
            },
            {
                id: 'profit',
                title: 'Profit per agent',
                why: 'Know which agents and plans make the brokerage money.',
                steps: [
                    'Open the Profitability tab.',
                    'Each agent shows income, costs and profit.',
                ],
                try: {
                    label: 'Open Profitability',
                    href: '/compensation/profitability',
                },
            },
        ],
    },

    Settings: {
        title: 'Set up in minutes',
        intro: 'Your brokerage details, who can do what, billing, compliance and connected apps. Most of this is done once.',
        start: [
            'Fill in your brokerage details and licences (Brokerage section).',
            'Check who can do what (Brokerage › Roles).',
            'Follow the setup guide in Getting started.',
        ],
        lessons: [
            {
                id: 'brokerage',
                title: 'Your brokerage details',
                why: 'Your name, licences and logo appear on contracts, emails and your website.',
                steps: [
                    'Open Brokerage › Offices and teams.',
                    'Fill in your name, address and phone.',
                    'Add your licences in Brokerage › Licences.',
                ],
                try: {
                    label: 'Open Brokerage details',
                    href: '/settings/organisation',
                },
            },
            {
                id: 'roles',
                title: 'Who can do what',
                why: 'Each person sees only what they need.',
                steps: [
                    'Open Brokerage › Roles.',
                    'Pick a role, for example "Transaction coordinator".',
                    'Tick what they may see and do.',
                ],
                try: { label: 'Open Roles', href: '/settings/roles' },
            },
            {
                id: 'billing',
                title: 'Billing and AI credits',
                why: 'Your plan, invoices and the AI credits used this month.',
                steps: [
                    'Open Billing.',
                    'See your plan and invoices.',
                    'See AI credits used this month, and add more if needed.',
                ],
                try: { label: 'Open Billing', href: '/settings/billing' },
            },
            {
                id: 'compliance',
                title: 'Compliance',
                why: 'Fair housing, privacy and approvals, kept in order.',
                steps: [
                    'Open Compliance.',
                    'Check the fair-housing and privacy settings.',
                    'Review who has access each quarter (Access review).',
                ],
                tips: [
                    'Required legal text and disclaimers are read-only and cannot be removed.',
                ],
                try: {
                    label: 'Open Compliance',
                    href: '/settings/fair-housing',
                },
            },
            {
                id: 'apps',
                title: 'Connect your other apps',
                why: 'Bring in data from the tools you already use.',
                steps: [
                    'Open Apps.',
                    'Pick an app and press Connect.',
                    'Choose Test first, then Live when you are happy.',
                ],
                try: { label: 'Open Apps', href: '/apps' },
            },
        ],
    },
};

/** Agents see their own words where their work differs from the owner's. */
const AGENT: Record<string, Guide> = {
    Transactions: {
        ...OWNER.Transactions,
        title: 'Close without stress',
        intro: 'Your deals from signed contract to closing. RightAlly reads your contract, sets every date, builds your checklist and reminds you, so your file is complete and approved the first time.',
        lessons: OWNER.Transactions.lessons.filter((l) => l.id !== 'review'),
    },
    'My commissions': {
        title: 'Grow your earnings',
        intro: 'See what you have earned, what is coming, how close you are to your cap, and the revenue share you earn from agents you brought in.',
        start: [
            'Open "My money" to see this year\'s earnings.',
            'Check your plan and how close you are to your cap.',
            'Invite an agent you know to earn revenue share.',
        ],
        lessons: [
            {
                id: 'my-money',
                title: 'What you have earned and what is coming',
                why: 'Your income this year and the deals still to pay.',
                steps: [
                    'Open the My money tab.',
                    'Paid deals show the date and amount.',
                    'Pending deals show the expected amount and closing date.',
                ],
                ai: {
                    name: ASK,
                    what: 'Answers questions about your income.',
                    example:
                        'How much will I earn from deals closing this month?',
                },
                try: { label: 'Open My money', href: '/my-money' },
            },
            {
                id: 'my-plan',
                title: 'Your plan and cap',
                why: 'Know exactly how you are paid and when you hit your cap.',
                steps: [
                    'Open the My plan tab.',
                    'See your split, fees and cap.',
                    'The bar shows how close you are to your cap.',
                ],
                try: { label: 'Open My plan', href: '/my-plan' },
            },
            {
                id: 'revenue-share',
                title: 'Revenue share',
                why: 'Earn a share when agents you brought in close deals.',
                steps: [
                    'Open the Revenue share tab.',
                    'See each agent you brought in and what you earned from them.',
                    'Invite an agent from "Refer an agent".',
                ],
                try: { label: 'Open Revenue share', href: '/revenue-share' },
            },
        ],
    },
    Training: {
        title: 'Learn faster',
        intro: "Short lessons, your brokerage's policies and the team directory. Lessons are picked for what you are working on now.",
        start: [
            'Open Training and start the lesson at the top.',
            "Read your brokerage's policies once.",
            'Find anyone on the team in the Directory.',
        ],
        lessons: [
            {
                id: 'lessons',
                title: 'Lessons picked for you',
                why: 'Learn exactly what helps your next deal.',
                steps: [
                    'Open the Training tab.',
                    'Start the lesson at the top. Most take 5 minutes.',
                    'Your progress is saved.',
                ],
                ai: {
                    name: 'Learn-IQ',
                    what: 'Picks your next lesson from what you are doing, and quizzes you in practice role-plays.',
                    example: 'Help me practise a listing appointment.',
                },
                try: { label: 'Open Training', href: '/learn' },
            },
            {
                id: 'policies',
                title: 'Brokerage policies',
                why: 'Know the rules your brokerage follows.',
                steps: [
                    'Open the Policies tab.',
                    'Read each policy and press "I have read this".',
                ],
                try: { label: 'Open Policies', href: '/policies' },
            },
            {
                id: 'directory',
                title: 'Team directory',
                why: 'Find who to ask, fast.',
                steps: ['Open the Directory tab.', 'Search by name or role.'],
                try: { label: 'Open Directory', href: '/directory' },
            },
        ],
    },
};

/** The guide behind a main menu, in the words for this person (agents and their assistants get their own). */
export function guideFor(menuKey: string, kind: string): Guide | null {
    const agent = kind === 'agent' || kind === 'assistant';

    return (
        (agent ? AGENT[menuKey] : undefined) ??
        OWNER[menuKey] ??
        AGENT[menuKey] ??
        null
    );
}
