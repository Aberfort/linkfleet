import { Link as RouterLink } from 'react-router-dom';
import { Alert, Button } from '@mui/material';

interface PlanLimitNoticeProps {
    message: string;
    /** Where the plans of the workspace that hit its limit are. */
    workspaceId: number;
    sx?: object;
}

/**
 * Shown where a "your plan is full" refusal happened, instead of a toast that
 * vanishes: it says what, and takes the person to where it can be fixed.
 */
function PlanLimitNotice({ message, workspaceId, sx }: PlanLimitNoticeProps) {
    return (
        <Alert
            severity="warning"
            sx={sx}
            action={
                <Button color="inherit" size="small" component={RouterLink} to={`/workspaces/${workspaceId}/billing`}>
                    Переглянути тарифи
                </Button>
            }
        >
            {message}
        </Alert>
    );
}

export default PlanLimitNotice;
