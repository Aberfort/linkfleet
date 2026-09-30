import { useRef, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Formik, Form } from 'formik';
import * as Yup from 'yup';
import { toast } from 'react-toastify';
import { Box, Button, IconButton, Paper, Stack, TextField, Tooltip, Typography } from '@mui/material';
import ContentCopyIcon from '@mui/icons-material/ContentCopy';
import { createSite } from '../api/sites';
import { createLink } from '../api/links';
import { errorMessage, validationErrors } from '../api/errors';
import { planLimitOf } from '../utils/planLimit';
import { canEdit, isOwner } from '../utils/roles';
import PlanLimitNotice from './PlanLimitNotice';
import type { Link, PlanLimitPayload, Site, Workspace } from '../types';

const validationSchema = Yup.object({
    target_url: Yup.string().url('Введіть повний URL, напр. https://example.com').required("URL є обов'язковим"),
});

/** What the first site is called when the person has not been asked to name one. */
export const FIRST_SITE_NAME = 'Мої посилання';

interface GettingStartedProps {
    sites: Site[];
    workspaces: Workspace[];
    /** The person is done with this card (or got what they came for); refresh and hide it. */
    onFinished: () => void;
}

/**
 * Shown to someone with no links yet, in place of an empty table: one field,
 * one button, and a short link at the end of it. Everything else - a site to
 * put it in, a workspace to put that in - is arranged behind the scenes, and
 * can be renamed or moved on later.
 */
function GettingStarted({ sites, workspaces, onFinished }: GettingStartedProps) {
    const [created, setCreated] = useState<{ site: Site; link: Link } | null>(null);
    const [planLimit, setPlanLimit] = useState<PlanLimitPayload | null>(null);
    // If the site is made but the link is refused, a retry must not make a second site.
    const madeSite = useRef<Site | null>(null);

    const writable = workspaces.filter((workspace) => canEdit(workspace.role));
    const existing = sites.find((site) => canEdit(site.role));
    const workspace = writable.find((w) => isOwner(w.role)) ?? writable[0];

    if (!existing && !workspace) {
        return null;
    }

    const copy = async (text: string) => {
        try {
            await navigator.clipboard.writeText(text);
            toast.success('Скопійовано.');
        } catch {
            toast.error('Не вдалося скопіювати.');
        }
    };

    if (created) {
        return (
            <Paper variant="outlined" sx={{ p: 3, mb: 3 }} aria-label="Перше посилання створено">
                <Typography variant="h6" gutterBottom>
                    Готово — ось ваше перше посилання
                </Typography>
                <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 2, flexWrap: 'wrap' }}>
                    <code style={{ fontSize: '1.1rem', wordBreak: 'break-all' }}>{created.link.short_url}</code>
                    <Tooltip title="Копіювати">
                        <IconButton size="small" onClick={() => copy(created.link.short_url)} aria-label="Копіювати посилання">
                            <ContentCopyIcon fontSize="inherit" />
                        </IconButton>
                    </Tooltip>
                </Box>
                <Typography color="text.secondary" sx={{ mb: 2, maxWidth: '65ch' }}>
                    Відкрийте його — перший клік одразу з’явиться в аналітиці. Далі можна додати власний домен,
                    QR-код чи пароль до посилання.
                </Typography>
                <Stack direction="row" spacing={1} flexWrap="wrap" useFlexGap>
                    <Button variant="contained" component="a" href={created.link.short_url} target="_blank" rel="noopener noreferrer">
                        Відкрити посилання
                    </Button>
                    <Button variant="outlined" component={RouterLink} to={`/sites/${created.site.id}/analytics`}>
                        Аналітика
                    </Button>
                    <Button component={RouterLink} to={`/sites/${created.site.id}/links`}>
                        Усі посилання
                    </Button>
                    <Button onClick={onFinished}>Готово</Button>
                </Stack>
            </Paper>
        );
    }

    return (
        <Paper variant="outlined" sx={{ p: 3, mb: 3 }} aria-label="Створіть перше посилання">
            <Typography variant="h6" gutterBottom>
                Створіть перше посилання
            </Typography>
            <Typography color="text.secondary" sx={{ mb: 2, maxWidth: '65ch' }}>
                Вставте адресу, куди воно має вести, — решту ми підготуємо самі.
            </Typography>

            {planLimit && (
                <PlanLimitNotice message={planLimit.message} workspaceId={planLimit.workspace_id} sx={{ mb: 2 }} />
            )}

            <Formik
                initialValues={{ target_url: '' }}
                validationSchema={validationSchema}
                onSubmit={async (values, { setSubmitting, setErrors }) => {
                    try {
                        const site =
                            existing ??
                            madeSite.current ??
                            (madeSite.current = await createSite({ workspace_id: workspace.id, name: FIRST_SITE_NAME }));
                        const link = await createLink(site.id, { target_url: values.target_url });

                        setPlanLimit(null);
                        setCreated({ site, link });
                    } catch (error) {
                        const limit = planLimitOf(error);

                        if (limit) {
                            setPlanLimit(limit);
                        } else {
                            setPlanLimit(null);
                            setErrors(validationErrors(error));
                            toast.error(errorMessage(error, 'Не вдалося створити посилання.'));
                        }
                    } finally {
                        setSubmitting(false);
                    }
                }}
            >
                {({ isSubmitting, errors, touched, values, handleChange }) => (
                    <Form>
                        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} alignItems="flex-start">
                            <TextField
                                fullWidth
                                label="Куди веде посилання"
                                name="target_url"
                                placeholder="https://example.com/my-page"
                                value={values.target_url}
                                onChange={handleChange}
                                error={touched.target_url && Boolean(errors.target_url)}
                                helperText={touched.target_url && errors.target_url}
                            />
                            <Button type="submit" variant="contained" disabled={isSubmitting} sx={{ minWidth: 160, mt: '2px' }}>
                                Скоротити
                            </Button>
                        </Stack>
                    </Form>
                )}
            </Formik>
        </Paper>
    );
}

export default GettingStarted;
