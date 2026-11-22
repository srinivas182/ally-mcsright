import { CopyText, SetupGuide } from '@/components/setup-guide';

/**
 * HQ5: how to point your own web address at your RightAlly website, for people who have never changed DNS. Used on
 * the brokerage's website settings and on an agent's own website.
 */
export function DomainGuide({
    cnameTarget,
    example,
    connected,
}: {
    cnameTarget: string;
    example: string;
    /** True once one of your own domains is connected. */
    connected: boolean;
}) {
    const bare = example.replace(/^www\./, '');

    return (
        <SetupGuide
            title="How to use your own web address"
            intro="About 10 minutes of work, then a short wait. You need to be able to sign in where the address was bought."
            steps={[
                {
                    title: 'Type your address below, with www',
                    body: (
                        <p>
                            For example <strong>{example}</strong>, then click
                            Connect domain.
                        </p>
                    ),
                },
                {
                    title: 'Open the DNS settings where you bought it',
                    body: (
                        <p>
                            Sign in to GoDaddy, Namecheap, Squarespace Domains,
                            Cloudflare or wherever the address was bought. Find
                            your address and open <strong>DNS</strong> (often
                            called Manage DNS or DNS records). Not sure who that
                            is? Search your email for the domain's renewal
                            receipt.
                        </p>
                    ),
                },
                {
                    title: 'Add one record',
                    body: (
                        <>
                            <p>
                                Add a record with Type <strong>CNAME</strong>,
                                Name (or Host) <strong>www</strong>, and Value
                                (or Points to):
                            </p>
                            <CopyText value={cnameTarget} label="CNAME value" />
                            <p>
                                Leave the time (TTL) as it is and save. If a www
                                record is already there, change it instead of
                                adding a second one.
                            </p>
                        </>
                    ),
                },
                {
                    title: 'Send the address without www to it',
                    body: (
                        <p>
                            In the same place, look for Forwarding (or Redirect)
                            and forward <strong>{bare}</strong> to{' '}
                            <strong>https://{example}</strong>, so both work.
                        </p>
                    ),
                },
                {
                    title: 'Wait for it to connect',
                    done: connected,
                    body: (
                        <p>
                            Usually within an hour, at most two days. The label
                            below changes from Waiting for DNS to Connected, and
                            the padlock (https) is added for you. Nothing else
                            to do.
                        </p>
                    ),
                },
            ]}
        />
    );
}
