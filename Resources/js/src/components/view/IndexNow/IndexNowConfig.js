import React, {Fragment} from "react";
import {observer} from "mobx-react";
import {action, computed, observable} from "mobx";
import {Button, Heading, Icon, Loader, SingleSelect, Table} from "sulu-admin-bundle/components";
import {withToolbar} from "sulu-admin-bundle/containers";
import {translate} from "sulu-admin-bundle/utils";
import {Requester} from "sulu-admin-bundle/services";
import {SnackbarService} from "sulu-base-bundle";
import indexNowConfigStyles from "./indexNowConfig.scss";

const STALE_AFTER_DAYS = 7;
const MILLISECONDS_PER_DAY = 86400000;

const STATUS_ICONS = {
    success: "su-check-circle",
    partial: "su-exclamation-triangle",
    error: "su-ban",
};

const STATUS_STYLES = {
    success: indexNowConfigStyles.statusSuccess,
    partial: indexNowConfigStyles.statusPartial,
    error: indexNowConfigStyles.statusError,
};

@observer
class IndexNowConfig extends React.Component {
    @observable loading = false;
    @observable submitting = false;
    @observable loaded = false;
    @observable urlCount = 0;
    @observable lastRun = null;
    @observable lastSuccess = null;
    @observable statistics = null;
    @observable history = [];
    @observable pagination = {page: 1, limit: 20, total: 0, pages: 0};
    @observable expandedRows = [];
    @observable statusFilter = "";
    @observable triggerFilter = "";

    componentDidMount() {
        this.load().then();
    }

    @computed get busy() {
        return this.loading || this.submitting;
    }

    @computed get successRate() {
        const statistics = this.statistics;

        if (!statistics || !statistics.total) {
            return null;
        }

        return Math.round((statistics.successful / statistics.total) * 100);
    }

    @computed get daysSinceLastSuccess() {
        if (!this.lastSuccess) {
            return null;
        }

        const date = new Date(this.lastSuccess.submittedAt);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        return Math.floor((Date.now() - date.getTime()) / MILLISECONDS_PER_DAY);
    }

    @computed get alert() {
        if (!this.loaded) {
            return null;
        }

        if (!this.lastSuccess) {
            return this.lastRun ? "never_succeeded" : null;
        }

        if (this.daysSinceLastSuccess >= STALE_AFTER_DAYS) {
            return "stale";
        }

        if (this.lastRun && this.lastRun.status !== "success") {
            return "last_run_failed";
        }

        return null;
    }

    buildStatusQuery() {
        const parameters = new URLSearchParams();
        parameters.set("page", String(this.pagination.page));
        parameters.set("limit", String(this.pagination.limit));

        if (this.statusFilter) {
            parameters.set("status", this.statusFilter);
        }

        if (this.triggerFilter) {
            parameters.set("trigger", this.triggerFilter);
        }

        return parameters.toString();
    }

    @action load = () => {
        this.loading = true;

        return Promise.all([
            Requester.get("/admin/api/index-now/urls"),
            Requester.get("/admin/api/index-now/status?" + this.buildStatusQuery()),
        ])
            .then(action(([urlResponse, statusResponse]) => {
                this.urlCount = Array.isArray(urlResponse.urls) ? urlResponse.urls.length : 0;
                this.applyStatus(statusResponse);
            }))
            .catch((e) => {
                console.error("Error while loading IndexNow data.", e);
                SnackbarService.show({
                    text: translate("app.index_now_load_error"),
                    type: "error",
                });
            })
            .finally(action(() => {
                this.loading = false;
                this.loaded = true;
            }));
    };

    @action loadHistory = () => {
        this.loading = true;

        return Requester.get("/admin/api/index-now/status?" + this.buildStatusQuery())
            .then(action((response) => {
                this.applyStatus(response);
            }))
            .catch((e) => {
                console.error("Error while loading IndexNow history.", e);
                SnackbarService.show({
                    text: translate("app.index_now_load_error"),
                    type: "error",
                });
            })
            .finally(action(() => {
                this.loading = false;
            }));
    };

    @action applyStatus = (response) => {
        this.lastRun = response.lastRun || null;
        this.lastSuccess = response.lastSuccess || null;
        this.statistics = response.statistics || null;
        this.history = Array.isArray(response.history) ? response.history : [];
        this.expandedRows = [];

        if (response.pagination) {
            this.pagination = response.pagination;
        }
    };

    @action indexNow = () => {
        this.submitting = true;

        return Requester.post("/admin/api/index-now/start")
            .then(action((response) => {
                if (Array.isArray(response.urls)) {
                    this.urlCount = response.urls.length;
                }

                this.statusFilter = "";
                this.triggerFilter = "";
                this.applyStatus(response);

                const summary = response.summary || {};
                const failedEngines = summary.failedEngines || 0;
                const submitted = response.submitted || 0;

                SnackbarService.show({
                    text: failedEngines > 0
                        ? translate("app.index_now_partial", {count: submitted, failed: failedEngines})
                        : translate("app.index_now_submitted", {count: submitted}),
                    type: failedEngines > 0 ? "error" : "success",
                });
            }))
            .catch((e) => {
                console.error("Error while submitting IndexNow URLs.", e);
                SnackbarService.show({
                    text: e?.message || translate("app.index_now_submit_error"),
                    type: "error",
                });
            })
            .finally(action(() => {
                this.submitting = false;
            }));
    };

    @action handleStatusFilterChange = (value) => {
        this.statusFilter = value;
        this.pagination = {...this.pagination, page: 1};
        this.loadHistory().then();
    };

    @action handleTriggerFilterChange = (value) => {
        this.triggerFilter = value;
        this.pagination = {...this.pagination, page: 1};
        this.loadHistory().then();
    };

    @action handlePageChange = (page) => {
        this.pagination = {...this.pagination, page};
        this.loadHistory().then();
    };

    @action handleRowExpand = (rowId) => {
        this.expandedRows = [...this.expandedRows, rowId];
    };

    @action handleRowCollapse = (rowId) => {
        this.expandedRows = this.expandedRows.filter((id) => id !== rowId);
    };

    formatDate = (value) => {
        if (!value) {
            return null;
        }

        const date = new Date(value);

        if (Number.isNaN(date.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat(undefined, {
            dateStyle: "medium",
            timeStyle: "short",
        }).format(date);
    };

    formatRelative = (value) => {
        if (!value) {
            return null;
        }

        const date = new Date(value);
        if (Number.isNaN(date.getTime())) {
            return null;
        }

        const seconds = Math.round((Date.now() - date.getTime()) / 1000);
        const units = [
            ["day", 86400],
            ["hour", 3600],
            ["minute", 60],
        ];

        for (const [unit, secondsInUnit] of units) {
            if (Math.abs(seconds) >= secondsInUnit) {
                return new Intl.RelativeTimeFormat(undefined, {numeric: "auto"})
                    .format(-Math.round(seconds / secondsInUnit), unit);
            }
        }

        return new Intl.RelativeTimeFormat(undefined, {numeric: "auto"}).format(0, "minute");
    };

    formatDuration = (milliseconds) => {
        if (milliseconds === null || milliseconds === undefined) {
            return null;
        }

        if (milliseconds < 1000) {
            return translate("app.index_now_duration_ms", {duration: milliseconds});
        }

        return translate("app.index_now_duration_s", {duration: (milliseconds / 1000).toFixed(1)});
    };

    translateTrigger = (trigger) => translate(
        trigger === "manual" ? "app.index_now_trigger_manual" : "app.index_now_trigger_automatic",
    );

    translateStatus = (status) => {
        if (status === "success") {
            return translate("app.index_now_status_success");
        }

        if (status === "partial") {
            return translate("app.index_now_status_partial");
        }

        return translate("app.index_now_status_error");
    };

    renderTrigger(trigger) {
        const triggerStyle = trigger === "manual"
            ? indexNowConfigStyles.triggerManual
            : indexNowConfigStyles.triggerAutomatic;

        return (
            <span className={`${indexNowConfigStyles.trigger} ${triggerStyle}`}>
                {this.translateTrigger(trigger)}
            </span>
        );
    }

    renderStatus(status) {
        return (
            <span className={`${indexNowConfigStyles.status} ${STATUS_STYLES[status] || ""}`}>
                <Icon name={STATUS_ICONS[status] || STATUS_ICONS.error} />
                {this.translateStatus(status)}
            </span>
        );
    }

    renderEngineDots(submission) {
        const engines = submission.engines || [];

        if (engines.length === 0) {
            return null;
        }

        return (
            <div className={indexNowConfigStyles.engineDots}>
                {engines.map((engine) => (
                    <span
                        className={`${indexNowConfigStyles.engineDot} ${
                            engine.status === "success"
                                ? indexNowConfigStyles.engineDotSuccess
                                : indexNowConfigStyles.engineDotError
                        }`}
                        key={engine.name}
                        title={
                            engine.name
                            + ": "
                            + this.translateStatus(engine.status === "success" ? "success" : "error")
                        }
                    />
                ))}
                <span className={indexNowConfigStyles.engineRatio}>
                    {submission.successfulEngines}
                    {"/"}
                    {submission.successfulEngines + submission.failedEngines}
                </span>
            </div>
        );
    }

    renderAlert() {
        const alert = this.alert;

        if (!alert) {
            return null;
        }

        const messages = {
            stale: translate("app.index_now_alert_stale", {days: this.daysSinceLastSuccess}),
            never_succeeded: translate("app.index_now_alert_never_succeeded"),
            last_run_failed: translate("app.index_now_alert_last_run_failed"),
        };

        const alertStyle = alert === "last_run_failed"
            ? indexNowConfigStyles.alertWarning
            : indexNowConfigStyles.alertError;

        return (
            <div className={`${indexNowConfigStyles.alert} ${alertStyle}`}>
                <Icon className={indexNowConfigStyles.alertIcon} name="su-exclamation-triangle" />
                <div className={indexNowConfigStyles.alertBody}>
                    <div className={indexNowConfigStyles.alertText}>{messages[alert]}</div>
                    <div className={indexNowConfigStyles.alertHint}>
                        {translate("app.index_now_alert_hint")}
                    </div>
                </div>
                <Button
                    disabled={this.busy}
                    loading={this.submitting}
                    onClick={this.indexNow}
                    skin="primary"
                >
                    {translate("app.index_now_start")}
                </Button>
            </div>
        );
    }

    renderCard(label, value, meta, valueStyle) {
        return (
            <div className={indexNowConfigStyles.card}>
                <div className={indexNowConfigStyles.cardLabel}>{label}</div>
                <div className={`${indexNowConfigStyles.cardValue} ${valueStyle || ""}`}>{value}</div>
                <div className={indexNowConfigStyles.cardMeta}>{meta}</div>
            </div>
        );
    }

    renderHealthCard() {
        const rate = this.successRate;
        const statistics = this.statistics;

        if (rate === null) {
            return this.renderCard(
                translate("app.index_now_card_health"),
                "—",
                translate("app.index_now_never_hint"),
            );
        }

        const rateStyle = rate === 100
            ? indexNowConfigStyles.statusSuccess
            : (rate >= 80 ? indexNowConfigStyles.statusPartial : indexNowConfigStyles.statusError);

        const averageDuration = this.formatDuration(statistics.averageDurationMs);

        return this.renderCard(
            translate("app.index_now_card_health"),
            rate + "%",
            (
                <Fragment>
                    {translate("app.index_now_health_detail", {
                        successful: statistics.successful,
                        total: statistics.total,
                    })}
                    {averageDuration && (
                        <span className={indexNowConfigStyles.cardMetaSecondary}>
                            {translate("app.index_now_average_duration", {duration: averageDuration})}
                        </span>
                    )}
                </Fragment>
            ),
            rateStyle,
        );
    }

    renderFilters() {
        return (
            <div className={indexNowConfigStyles.filters}>
                <div className={indexNowConfigStyles.filter}>
                    <label className={indexNowConfigStyles.filterLabel}>
                        {translate("app.index_now_column_status")}
                    </label>
                    <SingleSelect
                        disabled={this.busy}
                        onChange={this.handleStatusFilterChange}
                        value={this.statusFilter}
                    >
                        <SingleSelect.Option value="">
                            {translate("app.index_now_filter_all")}
                        </SingleSelect.Option>
                        <SingleSelect.Option value="success">
                            {translate("app.index_now_status_success")}
                        </SingleSelect.Option>
                        <SingleSelect.Option value="partial">
                            {translate("app.index_now_status_partial")}
                        </SingleSelect.Option>
                        <SingleSelect.Option value="error">
                            {translate("app.index_now_status_error")}
                        </SingleSelect.Option>
                    </SingleSelect>
                </div>

                <div className={indexNowConfigStyles.filter}>
                    <label className={indexNowConfigStyles.filterLabel}>
                        {translate("app.index_now_column_trigger")}
                    </label>
                    <SingleSelect
                        disabled={this.busy}
                        onChange={this.handleTriggerFilterChange}
                        value={this.triggerFilter}
                    >
                        <SingleSelect.Option value="">
                            {translate("app.index_now_filter_all")}
                        </SingleSelect.Option>
                        <SingleSelect.Option value="manual">
                            {translate("app.index_now_trigger_manual")}
                        </SingleSelect.Option>
                        <SingleSelect.Option value="automatic">
                            {translate("app.index_now_trigger_automatic")}
                        </SingleSelect.Option>
                    </SingleSelect>
                </div>

                <div className={indexNowConfigStyles.filterCount}>
                    {translate("app.index_now_result_count", {count: this.pagination.total})}
                </div>
            </div>
        );
    }

    renderPagination() {
        const {page, pages} = this.pagination;

        if (pages <= 1) {
            return null;
        }

        return (
            <div className={indexNowConfigStyles.pagination}>
                <Button
                    disabled={this.busy || page <= 1}
                    onClick={() => this.handlePageChange(page - 1)}
                    skin="secondary"
                >
                    {translate("app.index_now_previous")}
                </Button>
                <span className={indexNowConfigStyles.paginationLabel}>
                    {translate("app.index_now_page_status", {page, pages})}
                </span>
                <Button
                    disabled={this.busy || page >= pages}
                    onClick={() => this.handlePageChange(page + 1)}
                    skin="secondary"
                >
                    {translate("app.index_now_next")}
                </Button>
            </div>
        );
    }

    renderHistoryRows() {
        const rows = [];

        for (const submission of this.history) {
            const expanded = this.expandedRows.includes(submission.id);
            const engines = submission.engines || [];
            const duration = this.formatDuration(submission.durationMs);

            rows.push(
                <Table.Row
                    depth={0}
                    expanded={expanded}
                    hasChildren={engines.length > 0}
                    id={submission.id}
                    key={submission.id}
                >
                    <Table.Cell>
                        <div>{this.formatDate(submission.submittedAt)}</div>
                        <div className={indexNowConfigStyles.relative}>
                            {this.formatRelative(submission.submittedAt)}
                        </div>
                    </Table.Cell>
                    <Table.Cell>{this.renderTrigger(submission.trigger)}</Table.Cell>
                    <Table.Cell>{this.renderStatus(submission.status)}</Table.Cell>
                    <Table.Cell>
                        <div>{submission.urlCount}</div>
                        {duration && <div className={indexNowConfigStyles.relative}>{duration}</div>}
                    </Table.Cell>
                    <Table.Cell>{this.renderEngineDots(submission)}</Table.Cell>
                </Table.Row>,
            );

            if (!expanded) {
                continue;
            }

            for (const engine of engines) {
                const errors = engine.errors || [];

                rows.push(
                    <Table.Row
                        depth={1}
                        id={`${submission.id}-${engine.name}`}
                        key={`${submission.id}-${engine.name}`}
                    >
                        <Table.Cell>
                            <span className={indexNowConfigStyles.engineName}>{engine.name}</span>
                        </Table.Cell>
                        <Table.Cell />
                        <Table.Cell>
                            {this.renderStatus(engine.status === "success" ? "success" : "error")}
                        </Table.Cell>
                        <Table.Cell>
                            {engine.totalBatches > 1
                                ? translate("app.index_now_batch_status", {
                                    successful: engine.successfulBatches,
                                    total: engine.totalBatches,
                                })
                                : null}
                        </Table.Cell>
                        <Table.Cell>
                            {errors.length > 0
                                ? (
                                    <ul className={indexNowConfigStyles.errors}>
                                        {errors.map((error) => <li key={error}>{error}</li>)}
                                    </ul>
                                )
                                : null}
                        </Table.Cell>
                    </Table.Row>,
                );
            }
        }

        return rows;
    }

    renderHistory() {
        if (this.loading) {
            return (
                <div className={indexNowConfigStyles.loader}>
                    <Loader />
                </div>
            );
        }

        return (
            <Fragment>
                <Table
                    onRowCollapse={this.handleRowCollapse}
                    onRowExpand={this.handleRowExpand}
                    placeholderText={translate("app.index_now_history_empty")}
                    skin="light"
                >
                    <Table.Header>
                        <Table.HeaderCell>{translate("app.index_now_column_date")}</Table.HeaderCell>
                        <Table.HeaderCell>{translate("app.index_now_column_trigger")}</Table.HeaderCell>
                        <Table.HeaderCell>{translate("app.index_now_column_status")}</Table.HeaderCell>
                        <Table.HeaderCell>{translate("app.index_now_column_urls")}</Table.HeaderCell>
                        <Table.HeaderCell>{translate("app.index_now_column_engines")}</Table.HeaderCell>
                    </Table.Header>
                    <Table.Body>
                        {this.renderHistoryRows()}
                    </Table.Body>
                </Table>
                {this.renderPagination()}
            </Fragment>
        );
    }

    renderLastSuccessMeta() {
        if (!this.lastSuccess) {
            return translate("app.index_now_never_hint");
        }

        return (
            <Fragment>
                {this.formatRelative(this.lastSuccess.submittedAt)}
                {" — "}
                {this.translateTrigger(this.lastSuccess.trigger)}
            </Fragment>
        );
    }

    renderLastRunMeta() {
        if (!this.lastRun) {
            return translate("app.index_now_never_hint");
        }

        return (
            <Fragment>
                {this.renderStatus(this.lastRun.status)}
                {" — "}
                {this.translateTrigger(this.lastRun.trigger)}
            </Fragment>
        );
    }

    render() {
        const placeholder = this.loaded ? translate("app.index_now_never") : "—";

        return (
            <div className={indexNowConfigStyles.container}>
                {this.renderAlert()}

                <div className={indexNowConfigStyles.cards}>
                    {this.renderCard(
                        translate("app.index_now_card_urls"),
                        this.loaded ? this.urlCount : "—",
                        translate("app.index_now_card_urls_hint"),
                    )}

                    {this.renderCard(
                        translate("app.index_now_card_last_success"),
                        this.lastSuccess ? this.formatDate(this.lastSuccess.submittedAt) : placeholder,
                        this.loaded ? this.renderLastSuccessMeta() : null,
                    )}

                    {this.renderCard(
                        translate("app.index_now_card_last_run"),
                        this.lastRun ? this.formatDate(this.lastRun.submittedAt) : placeholder,
                        this.loaded ? this.renderLastRunMeta() : null,
                    )}

                    {this.renderHealthCard()}
                </div>

                <div className={indexNowConfigStyles.section}>
                    <Heading
                        description={translate("app.index_now_history_description")}
                        label={translate("app.index_now_history_headline")}
                    />
                    {this.renderFilters()}
                    {this.renderHistory()}
                </div>
            </div>
        );
    }
}

export default withToolbar(IndexNowConfig, function () {
    return {
        items: [
            {
                type: "button",
                label: translate("app.index_now_start"),
                icon: "su-sync",
                disabled: this.busy,
                loading: this.submitting,
                onClick: () => {
                    this.indexNow().then();
                },
            },
            {
                type: "button",
                label: translate("app.index_now_refresh"),
                icon: "su-clock",
                disabled: this.busy,
                onClick: () => {
                    this.load().then();
                },
            },
        ],
    };
});
