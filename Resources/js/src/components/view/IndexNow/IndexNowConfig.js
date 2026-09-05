import React, {Fragment} from "react";
import {observer} from "mobx-react";
import {action, computed, observable} from "mobx";
import {Heading, Icon, Loader, Table} from "sulu-admin-bundle/components";
import {withToolbar} from "sulu-admin-bundle/containers";
import {translate} from "sulu-admin-bundle/utils";
import {Requester} from "sulu-admin-bundle/services";
import {SnackbarService} from "sulu-base-bundle";
import indexNowConfigStyles from "./indexNowConfig.scss";

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
    @observable history = [];
    @observable expandedRows = [];

    componentDidMount() {
        this.load().then();
    }

    @computed get busy() {
        return this.loading || this.submitting;
    }

    @action load = () => {
        this.loading = true;

        return Promise.all([
            Requester.get("/admin/api/index-now/urls"),
            Requester.get("/admin/api/index-now/status"),
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

    @action applyStatus = (response) => {
        this.lastRun = response.lastRun || null;
        this.lastSuccess = response.lastSuccess || null;
        this.history = Array.isArray(response.history) ? response.history : [];
    };

    @action indexNow = () => {
        this.submitting = true;

        return Requester.post("/admin/api/index-now/start")
            .then(action((response) => {
                if (Array.isArray(response.urls)) {
                    this.urlCount = response.urls.length;
                }
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

    renderCard(label, value, meta) {
        return (
            <div className={indexNowConfigStyles.card}>
                <div className={indexNowConfigStyles.cardLabel}>{label}</div>
                <div className={indexNowConfigStyles.cardValue}>{value}</div>
                <div className={indexNowConfigStyles.cardMeta}>{meta}</div>
            </div>
        );
    }

    renderHistoryRows() {
        const rows = [];

        for (const submission of this.history) {
            const expanded = this.expandedRows.includes(submission.id);
            const engines = submission.engines || [];

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
                    <Table.Cell>{submission.urlCount}</Table.Cell>
                    <Table.Cell>
                        {translate("app.index_now_provider_status", {
                            successful: submission.successfulEngines,
                            total: submission.successfulEngines + submission.failedEngines,
                        })}
                    </Table.Cell>
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
                </div>

                <div className={indexNowConfigStyles.section}>
                    <Heading
                        description={translate("app.index_now_history_description")}
                        label={translate("app.index_now_history_headline")}
                    />
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
