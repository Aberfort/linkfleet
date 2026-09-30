import { Accordion, AccordionDetails, AccordionSummary, Grid, TextField, Typography } from '@mui/material';
import ExpandMoreIcon from '@mui/icons-material/ExpandMore';
import { applyUtm, isEditableUrl, readUtm, type UtmKey } from '../utils/utm';

interface Field {
    key: UtmKey;
    label: string;
    hint: string;
    suggestions?: string[];
}

const FIELDS: Field[] = [
    { key: 'utm_source', label: 'Джерело (utm_source)', hint: 'Звідки йде трафік: newsletter, twitter, partner-x' },
    {
        key: 'utm_medium',
        label: 'Канал (utm_medium)',
        hint: 'Тип каналу: email, social, cpc',
        suggestions: ['email', 'social', 'cpc', 'referral', 'affiliate', 'banner', 'sms', 'qr'],
    },
    { key: 'utm_campaign', label: 'Кампанія (utm_campaign)', hint: 'Назва акції: spring_sale' },
    { key: 'utm_term', label: 'Ключове слово (utm_term)', hint: 'Для платного пошуку' },
    { key: 'utm_content', label: 'Вміст (utm_content)', hint: 'Щоб відрізнити кнопки чи оголошення в одній кампанії' },
];

interface Props {
    /** The link's target URL. The tags live in it, not beside it. */
    url: string;
    onChange: (url: string) => void;
}

/**
 * A form over the target URL's UTM query parameters. There is nothing to
 * store separately: the fields read the URL and write back to it, so what
 * you see in the URL field is exactly what visitors will be sent to, and
 * editing that field by hand updates these ones.
 */
function UtmBuilder({ url, onChange }: Props) {
    const values = readUtm(url);
    const editable = isEditableUrl(url);

    return (
        <Accordion disableGutters defaultExpanded={Object.keys(values).length > 0} sx={{ mt: 2 }}>
            <AccordionSummary expandIcon={<ExpandMoreIcon />}>
                <Typography>UTM-мітки</Typography>
            </AccordionSummary>
            <AccordionDetails>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                    {editable
                        ? 'Мітки дописуються просто в цільовий URL вище — так їх бачитиме ваша аналітика (Google Analytics, Plausible…) на боці сайту.'
                        : 'Спершу введіть цільовий URL повністю, з https://.'}
                </Typography>
                <Grid container spacing={2}>
                    {FIELDS.map((field) => (
                        <Grid item xs={12} sm={6} key={field.key}>
                            <TextField
                                fullWidth
                                size="small"
                                label={field.label}
                                value={values[field.key] ?? ''}
                                disabled={!editable}
                                helperText={field.hint}
                                onChange={(e) => onChange(applyUtm(url, { ...values, [field.key]: e.target.value }))}
                                slotProps={field.suggestions ? { htmlInput: { list: `${field.key}-options` } } : undefined}
                            />
                            {field.suggestions && (
                                <datalist id={`${field.key}-options`}>
                                    {field.suggestions.map((option) => (
                                        <option key={option} value={option} />
                                    ))}
                                </datalist>
                            )}
                        </Grid>
                    ))}
                </Grid>
            </AccordionDetails>
        </Accordion>
    );
}

export default UtmBuilder;
