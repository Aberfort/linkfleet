import type { ReactNode } from 'react';
import { Box, Card, CardActions, CardContent, Typography } from '@mui/material';
import { formatLimit, resourceLabels, resourceOrder } from '../utils/planLimit';
import { priceFor } from '../utils/plans';
import type { BillingInterval, Plan } from '../types';

interface PlanCardsProps {
    plans: Plan[];
    interval: BillingInterval;
    /** Paddle price id -> what it costs this visitor, already formatted. */
    prices: Record<string, string>;
    /** Highlighted as the one in use. */
    currentKey?: string;
    /** The button (or note) at the foot of each card; the caller knows who is looking. */
    action: (plan: Plan, priceId: string | undefined) => ReactNode;
}

function PlanCards({ plans, interval, prices, currentKey, action }: PlanCardsProps) {
    return (
        <Box
            sx={{
                display: 'grid',
                gridTemplateColumns: { xs: '1fr', md: `repeat(${Math.max(plans.length, 1)}, 1fr)` },
                gap: 2,
            }}
        >
            {plans.map((plan) => {
                const priceId = priceFor(plan, interval);
                const forSale = Object.keys(plan.prices).length > 0;
                const current = plan.key === currentKey;

                return (
                    <Card
                        key={plan.key}
                        variant="outlined"
                        aria-label={`Тариф ${plan.name}`}
                        sx={{
                            display: 'flex',
                            flexDirection: 'column',
                            borderColor: current ? 'primary.main' : undefined,
                            borderWidth: current ? 2 : 1,
                        }}
                    >
                        <CardContent sx={{ flexGrow: 1 }}>
                            <Typography variant="h6">{plan.name}</Typography>
                            <Typography variant="h4" sx={{ mt: 1, mb: 2, fontVariantNumeric: 'tabular-nums' }}>
                                {!forSale ? (
                                    'Безкоштовно'
                                ) : priceId && prices[priceId] ? (
                                    <>
                                        {prices[priceId]}
                                        <Typography component="span" color="text.secondary">
                                            {' '}
                                            / {interval === 'yearly' ? 'рік' : 'міс'}
                                        </Typography>
                                    </>
                                ) : (
                                    <Typography component="span" color="text.secondary">
                                        Ціна — під час оплати
                                    </Typography>
                                )}
                            </Typography>

                            <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: '1fr auto', rowGap: 0.75 }}>
                                {resourceOrder.map((resource) => (
                                    <Box key={resource} sx={{ display: 'contents' }}>
                                        <Typography component="dt" color="text.secondary">
                                            {resourceLabels[resource]}
                                        </Typography>
                                        <Typography component="dd" sx={{ m: 0, fontVariantNumeric: 'tabular-nums' }}>
                                            {formatLimit(plan.limits[resource])}
                                        </Typography>
                                    </Box>
                                ))}
                            </Box>
                        </CardContent>
                        <CardActions sx={{ px: 2, pb: 2, minHeight: 52 }}>{action(plan, priceId)}</CardActions>
                    </Card>
                );
            })}
        </Box>
    );
}

export default PlanCards;
