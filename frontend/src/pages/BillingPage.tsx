import { useCallback, useEffect, useState } from 'react';
import { Link as RouterLink, Navigate, useParams } from 'react-router-dom';
import { toast } from 'react-toastify';
import {
    Container,
    Typography,
    Box,
    Paper,
    Button,
    Alert,
    AlertTitle,
    Breadcrumbs,
    Link,
    Chip,
    LinearProgress,
    ToggleButton,
    ToggleButtonGroup,
} from '@mui/material';
import { useAuth } from '../contexts/useAuth';
import { useConfig } from '../contexts/useConfig';
import { getWorkspace } from '../api/workspaces';
import {
    cancelSubscription,
    changePlan,
    getBilling,
    listPlans,
    paymentMethodUrl,
    resumeSubscription,
    startCheckout,
} from '../api/billing';
import { errorMessage } from '../api/errors';
import { openCheckout, previewPrices } from '../utils/paddle';
import { formatLimit, resourceLabels, resourceOrder } from '../utils/planLimit';
import PlanCards from '../components/PlanCards';
import { priceFor } from '../utils/plans';
import type { Billing, BillingInterval, LimitedResource, Plan, PlanList, Workspace } from '../types';

const POLL_EVERY_MS = 2000;
const POLL_TRIES = 15;

const sleep = (ms: number) => new Promise((resolve) => setTimeout(resolve, ms));

const formatDate = (iso: string) =>
    new Date(iso).toLocaleDateString('uk-UA', { day: 'numeric', month: 'long', year: 'numeric' });

const sourceNotes: Record<Billing['source'], string> = {
    'self-hosted': 'Тут нічого не продається — обмежень немає.',
    free: 'Безкоштовний тариф.',
    subscription: 'За підпискою.',
    granted: 'Тариф надано адміністратором.',
};

function UsageMeter({ resource, billing }: { resource: LimitedResource; billing: Billing }) {
    const used = billing.usage[resource];
    const limit = billing.limits[resource];
    const share = limit === null ? 0 : limit === 0 ? (used > 0 ? 100 : 0) : Math.min(100, (used / limit) * 100);
    const over = limit !== null && used > limit;
    const full = limit !== null && used >= limit;

    return (
        <Box>
            <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 0.5 }}>
                <Typography>{resourceLabels[resource]}</Typography>
                <Typography sx={{ fontVariantNumeric: 'tabular-nums' }} color={over ? 'error' : 'text.primary'}>
                    {used.toLocaleString('uk-UA')} / {limit === null ? '∞' : formatLimit(limit)}
                </Typography>
            </Box>
            {limit !== null && (
                <LinearProgress
                    variant="determinate"
                    value={share}
                    color={over ? 'error' : full ? 'warning' : 'primary'}
                    aria-label={`${resourceLabels[resource]}: використано ${used} з ${limit}`}
                />
            )}
        </Box>
    );
}

function BillingPage() {
    const { workspaceId } = useParams<{ workspaceId: string }>();
    const id = Number(workspaceId);
    const { user } = useAuth();
    const config = useConfig();
    const isDemo = Boolean(user?.is_demo);

    const [workspace, setWorkspace] = useState<Workspace | null>(null);
    const [billing, setBilling] = useState<Billing | null>(null);
    const [catalog, setCatalog] = useState<PlanList | null>(null);
    const [prices, setPrices] = useState<Record<string, string>>({});
    const [interval, setInterval] = useState<BillingInterval>('monthly');
    const [loading, setLoading] = useState(true);
    const [busy, setBusy] = useState(false);
    const [processing, setProcessing] = useState(false);

    const billingEnabled = config.billing.enabled;

    useEffect(() => {
        if (!billingEnabled) {
            return;
        }

        (async () => {
            try {
                const [ws, state, plans] = await Promise.all([getWorkspace(id), getBilling(id), listPlans()]);
                setWorkspace(ws);
                setBilling(state);
                setCatalog(plans);
            } catch (error) {
                toast.error(errorMessage(error, 'Помилка при завантаженні тарифу.'));
            } finally {
                setLoading(false);
            }
        })();
    }, [id, billingEnabled]);

    // What things cost is a nicety: the page works without it.
    useEffect(() => {
        if (!catalog?.checkout_available) {
            return;
        }

        const ids = catalog.plans.flatMap((plan) => Object.values(plan.prices));
        let cancelled = false;

        previewPrices(config.billing, ids)
            .then((found) => !cancelled && setPrices(found))
            .catch(() => undefined);

        return () => {
            cancelled = true;
        };
    }, [catalog, config.billing]);

    /** Paddle tells the server about a payment a moment after the customer sees "paid". */
    const waitForSubscription = useCallback(async () => {
        setProcessing(true);

        try {
            for (let attempt = 0; attempt < POLL_TRIES; attempt++) {
                const state = await getBilling(id);

                if (state.subscription) {
                    setBilling(state);
                    toast.success('Тариф підключено.');

                    return;
                }

                await sleep(POLL_EVERY_MS);
            }

            toast.info('Оплату отримано — тариф з’явиться за кілька хвилин. Оновіть сторінку трохи згодом.');
        } catch (error) {
            toast.error(errorMessage(error, 'Не вдалося оновити тариф.'));
        } finally {
            setProcessing(false);
        }
    }, [id]);

    const run = async (task: () => Promise<void>, failure: string) => {
        setBusy(true);
        try {
            await task();
        } catch (error) {
            toast.error(errorMessage(error, failure));
        } finally {
            setBusy(false);
        }
    };

    const buy = (priceId: string) =>
        run(async () => {
            const options = await startCheckout(id, priceId);
            await openCheckout(config.billing, options, { onCompleted: waitForSubscription });
        }, 'Не вдалося відкрити оплату.');

    const change = (plan: Plan, priceId: string) => {
        if (!window.confirm(`Перейти на тариф ${plan.name}? Paddle перерахує оплату пропорційно.`)) {
            return;
        }

        return run(async () => {
            setBilling(await changePlan(id, priceId));
            toast.success(`Тариф змінено на ${plan.name}.`);
        }, 'Не вдалося змінити тариф.');
    };

    const cancel = () => {
        if (
            !window.confirm(
                'Скасувати підписку? Тариф діятиме до кінця вже оплаченого періоду, а тоді workspace повернеться на безкоштовний.'
            )
        ) {
            return;
        }

        return run(async () => {
            const state = await cancelSubscription(id);
            setBilling(state);
            toast.success('Підписку скасовано.');
        }, 'Не вдалося скасувати підписку.');
    };

    const resume = () =>
        run(async () => {
            setBilling(await resumeSubscription(id));
            toast.success('Підписка продовжиться.');
        }, 'Не вдалося відновити підписку.');

    const updateCard = () =>
        run(async () => {
            window.open(await paymentMethodUrl(id), '_blank', 'noopener');
        }, 'Не вдалося відкрити сторінку оплати.');

    if (config.loaded && !billingEnabled) {
        return <Navigate to="/workspaces" replace />;
    }

    if (loading || !billing || !catalog) {
        return (
            <Container maxWidth="lg" sx={{ mt: 4 }}>
                <Typography>Завантаження...</Typography>
            </Container>
        );
    }

    const subscription = billing.subscription;
    const canManage = billing.can_manage && !isDemo;
    const hasYearly = catalog.plans.some((plan) => plan.prices.yearly);

    const action = (plan: Plan, priceId: string | undefined) => {
        if (plan.key === billing.plan.key) {
            return <Chip label="Поточний тариф" color="primary" size="small" />;
        }

        if (!canManage) {
            return null;
        }

        if (Object.keys(plan.prices).length === 0) {
            // The free plan: getting there is ending the subscription.
            return subscription?.is_payer && !subscription.on_grace_period ? (
                <Button color="error" onClick={cancel} disabled={busy}>
                    Скасувати підписку
                </Button>
            ) : null;
        }

        if (!priceId) {
            return null;
        }

        if (!subscription) {
            return (
                <Button
                    variant="contained"
                    onClick={() => buy(priceId)}
                    disabled={busy || processing || !catalog.checkout_available}
                >
                    Обрати {plan.name}
                </Button>
            );
        }

        return subscription.is_payer && !subscription.past_due ? (
            <Button variant="outlined" onClick={() => change(plan, priceId)} disabled={busy}>
                Перейти на {plan.name}
            </Button>
        ) : null;
    };

    return (
        <Container maxWidth="lg" sx={{ mt: 4, mb: 6 }}>
            <Breadcrumbs sx={{ mb: 2 }}>
                <Link component={RouterLink} to="/workspaces" underline="hover">
                    Workspaces
                </Link>
                <Typography color="text.primary">{workspace?.name ?? '...'}</Typography>
            </Breadcrumbs>

            <Typography variant="h4" gutterBottom>
                Тариф
            </Typography>
            <Typography color="text.secondary" sx={{ mb: 3 }}>
                Тариф визначає, скільки посилань, власних доменів і учасників може мати workspace. Функції однакові
                на всіх тарифах. Якщо ліміт вичерпано, наявні посилання й далі працюють — просто нові додати не
                вийде.
            </Typography>

            {isDemo && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Демо-акаунт лише для читання — змінити тариф не можна.
                </Alert>
            )}
            {!isDemo && !billing.can_manage && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Змінювати тариф може лише власник workspace.
                </Alert>
            )}

            {processing && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Оплату отримано, підключаємо тариф…
                </Alert>
            )}

            {subscription?.past_due && (
                <Alert
                    severity="error"
                    sx={{ mb: 2 }}
                    action={
                        subscription.is_payer && (
                            <Button color="inherit" size="small" onClick={updateCard} disabled={busy}>
                                Оновити картку
                            </Button>
                        )
                    }
                >
                    <AlertTitle>Останній платіж не пройшов</AlertTitle>
                    Paddle спробує ще раз. Поки що тариф діє, але якщо оплата так і не пройде, підписку буде
                    скасовано.
                </Alert>
            )}

            {subscription?.on_grace_period && subscription.ends_at && (
                <Alert
                    severity="warning"
                    sx={{ mb: 2 }}
                    action={
                        subscription.is_payer && canManage ? (
                            <Button color="inherit" size="small" onClick={resume} disabled={busy}>
                                Не скасовувати
                            </Button>
                        ) : undefined
                    }
                >
                    Підписку скасовано. Тариф {billing.plan.name} діє до {formatDate(subscription.ends_at)}, потім
                    workspace повернеться на безкоштовний.
                </Alert>
            )}

            {subscription && !subscription.is_payer && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Підписку оплачує {subscription.payer_name ?? 'інший користувач'} — лише він може змінити або
                    скасувати її.
                </Alert>
            )}

            {billing.over_limit.length > 0 && (
                <Alert severity="warning" sx={{ mb: 2 }}>
                    <AlertTitle>Ліміт тарифу перевищено</AlertTitle>
                    {billing.over_limit.map((r) => resourceLabels[r].toLowerCase()).join(', ')}: у workspace більше,
                    ніж дозволяє {billing.plan.name}. Усе наявне продовжує працювати, але додати нове можна буде
                    лише після зменшення кількості або переходу на вищий тариф.
                </Alert>
            )}

            <Paper variant="outlined" sx={{ p: 3, mb: 4 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1.5, mb: 2, flexWrap: 'wrap' }}>
                    <Typography variant="h6" component="h2">
                        {billing.plan.name}
                    </Typography>
                    <Typography color="text.secondary">{sourceNotes[billing.source]}</Typography>
                    {subscription?.status === 'past_due' && <Chip size="small" color="error" label="Платіж не пройшов" />}
                </Box>
                <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' } }}>
                    {resourceOrder.map((resource) => (
                        <UsageMeter key={resource} resource={resource} billing={billing} />
                    ))}
                </Box>
                {subscription?.is_payer && canManage && !subscription.on_grace_period && (
                    <Button sx={{ mt: 2 }} onClick={updateCard} disabled={busy}>
                        Змінити спосіб оплати
                    </Button>
                )}
            </Paper>

            {!catalog.checkout_available && (
                <Alert severity="info" sx={{ mb: 2 }}>
                    Оплата ще не налаштована на цьому сервері — тарифи показано, але купити їх поки не можна.
                </Alert>
            )}

            {hasYearly && (
                <ToggleButtonGroup
                    exclusive
                    size="small"
                    value={interval}
                    onChange={(_, next: BillingInterval | null) => next && setInterval(next)}
                    aria-label="Період оплати"
                    sx={{ mb: 2 }}
                >
                    <ToggleButton value="monthly">Щомісяця</ToggleButton>
                    <ToggleButton value="yearly">Щороку</ToggleButton>
                </ToggleButtonGroup>
            )}

            <PlanCards
                plans={catalog.plans}
                interval={interval}
                prices={prices}
                currentKey={billing.plan.key}
                action={(plan) => action(plan, priceFor(plan, interval))}
            />
        </Container>
    );
}

export default BillingPage;
